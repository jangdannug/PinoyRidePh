<?php
declare(strict_types=1);

// Shared driver (rider) ingestion logic for rider_create.php (single +
// paste-batch entry). The INSERT pipeline mirrors import_rider.php's inline
// transaction exactly — riders + rider_vehicle_details + rider_address +
// top_ph_ekyc_details + two wallet rows — so a driver registered here ends up
// structurally identical to one brought in by CSV.
//
// ⚠ SYNC RULE: import_rider.php keeps its own inline copies of these helpers
// and of the INSERT sequence. If you change one side (columns, flags, wallet
// types, eKYC placeholders), change BOTH. They are intentionally not
// refactored onto one path so the proven CSV importer stays untouched.

const IMG_MAX_LEN = 100;

function rider_normalize_mobile_to_63(string $raw): array
{
    $digits = preg_replace('/\D/', '', $raw) ?? '';
    if ($digits === '') {
        return ['', true];
    }
    if (preg_match('/^63\d{10}$/', $digits)) {
        return [$digits, true];
    }
    if (preg_match('/^0\d{10}$/', $digits)) {
        return ['63' . substr($digits, 1), true];
    }
    if (preg_match('/^9\d{9}$/', $digits)) {
        return ['63' . $digits, true];
    }
    return [$digits, false];
}

function interpret_vehicle_type(string $raw): int
{
    return (stripos(trim($raw), 'motorcycle') !== false) ? 1 : 2;
}

// Image cells (license/OR-CR) are expected to be short filenames/paths.
function rider_cap_image_value(string $v): array
{
    if (strlen($v) > IMG_MAX_LEN) {
        return ['invalid.jpg', true];
    }
    return [$v, false];
}

function rider_exists_by_mobile(PDO $pdo, string $mobileNo): bool
{
    if ($mobileNo === '') return false;
    $stmt = $pdo->prepare('SELECT 1 FROM public.riders WHERE mobile_no = :mobile_no LIMIT 1');
    $stmt->execute([':mobile_no' => $mobileNo]);
    return $stmt->fetchColumn() !== false;
}

// Batch version used by review-time duplicate detection.
function riders_mobiles_exist(PDO $pdo, array $mobiles): array
{
    $mobiles = array_values(array_unique(array_filter($mobiles)));
    if ($mobiles === []) return [];
    $in = implode(',', array_fill(0, count($mobiles), '?'));
    $st = $pdo->prepare("SELECT mobile_no FROM public.riders WHERE mobile_no IN ($in)");
    $st->execute($mobiles);
    return $st->fetchAll(PDO::FETCH_COLUMN);
}

// DRN-{YY}{MM}-{N}, continuing the highest existing sequence number.
function next_rider_code_seq(PDO $pdo): int
{
    $stmt = $pdo->query(
        "SELECT COALESCE(MAX(NULLIF(regexp_replace(split_part(code, '-', 3), '\\D', '', 'g'), '')::int), 0)
         FROM public.riders
         WHERE code LIKE 'DRN-%'"
    );
    return (int)$stmt->fetchColumn() + 1;
}

function generate_rider_code(string $createdAt, int $seq): string
{
    $ts = strtotime($createdAt) ?: time();
    return sprintf('DRN-%s%s-%d', date('y', $ts), date('m', $ts), $seq);
}

// Dummy placeholder — same scheme the CSV import uses (required column, no
// real license number available at intake time).
function generate_dummy_license_no(): string
{
    return sprintf('DUM-34-%06d', random_int(0, 999999));
}

function generate_uuid_v4(): string
{
    $data = random_bytes(16);
    $data[6] = chr((ord($data[6]) & 0x0f) | 0x40);
    $data[8] = chr((ord($data[8]) & 0x3f) | 0x80);
    $hex = bin2hex($data);
    return substr($hex, 0, 8) . '-' . substr($hex, 8, 4) . '-' . substr($hex, 12, 4)
        . '-' . substr($hex, 16, 4) . '-' . substr($hex, 20, 12);
}

// wallet.ref_code continues the global sequential 6-digit series.
function next_wallet_ref_seq(PDO $pdo): int
{
    $stmt = $pdo->query(
        "SELECT COALESCE(MAX(ref_code::bigint), 0)
         FROM public.wallet
         WHERE ref_code ~ '^[0-9]+$'"
    );
    return (int)$stmt->fetchColumn() + 1;
}

function generate_wallet_ref_code(int $seq): string
{
    return sprintf('%06d', $seq);
}

// Validate + normalize one driver's fields (same issue vocabulary as the CSV
// import's map_row, minus the created_at guessing which is form-side).
function validate_rider_fields(
    string $firstName,
    string $lastName,
    string $mobileRaw,
    string $email,
    string $address,
    string $vTypeRaw,
    string $vBrand,
    string $vModel,
    string $vColor,
    string $vPlate,
    string $licenseImgRaw,
    string $orCrImgRaw
): array {
    [$mobileNo, $mobileRecognized] = rider_normalize_mobile_to_63($mobileRaw);
    [$licenseImg, $licenseCapped] = rider_cap_image_value(trim($licenseImgRaw));
    [$orCrImg, $orCrCapped]       = rider_cap_image_value(trim($orCrImgRaw));

    $mapped = [
        'first_name'          => trim($firstName),
        'last_name'           => trim($lastName),
        'drivers_license_img' => $licenseImg,
        'mobile_no'           => $mobileNo,
        'mobile_recognized'   => $mobileRecognized,
        'email_address'       => trim($email),
        'v_type_raw'          => trim($vTypeRaw),
        'v_type'              => interpret_vehicle_type($vTypeRaw),
        'v_brand'             => trim($vBrand),
        'v_model'             => trim($vModel),
        'v_color'             => trim($vColor),
        'v_plate_number'      => trim($vPlate),
        'v_or_cr_img'         => $orCrImg,
        'address'             => trim($address),
    ];

    $issues = [];
    if ($mapped['first_name'] === '') $issues[] = 'Missing first name';
    if ($mapped['last_name'] === '')  $issues[] = 'Missing last name';
    if ($mapped['mobile_no'] === '') {
        $issues[] = 'Missing mobile number';
    } elseif (!$mapped['mobile_recognized']) {
        $issues[] = 'Mobile number format not recognized — stored as digits-only, please review';
    }
    if ($licenseCapped) $issues[] = "Driver's license image value over " . IMG_MAX_LEN . ' chars — replaced with invalid.jpg';
    if ($orCrCapped)    $issues[] = 'OR/CR image value over ' . IMG_MAX_LEN . ' chars — replaced with invalid.jpg';
    $mapped['issues'] = $issues;

    return $mapped;
}
function insert_rider_record(PDO $pdo, array $mapped, string $createdAt, int $seq, int $walletSeq): array
{
    $code              = generate_rider_code($createdAt, $seq);
    $driversLicenseNo  = generate_dummy_license_no();
    $ekycRequestUserId = $mapped['mobile_no'] . '-' . $code;

    try {
        $pdo->beginTransaction();

        $riderStmt = $pdo->prepare(
            "INSERT INTO public.riders
                (code, first_name, last_name, drivers_license_img, drivers_license_no,
                mobile_no, email_address, created_at, updated_at, updated_by,
                is_verified, auto_accept, is_available, is_online, is_success_kyc, status,application_status,
                ekyc_request_user_id)
             VALUES
                (:code, :first_name, :last_name, :drivers_license_img, :drivers_license_no,
                :mobile_no, :email_address, :created_at, :updated_at, :updated_by,
                :is_verified, :auto_accept, :is_available, :is_online, :is_success_kyc, :status, :application_status,
                :ekyc_request_user_id)
             RETURNING id"
        );
        $riderStmt->execute([
            ':code'                 => $code,
            ':first_name'           => $mapped['first_name'],
            ':last_name'            => $mapped['last_name'],
            ':drivers_license_img'  => $mapped['drivers_license_img'],
            ':drivers_license_no'   => $driversLicenseNo,
            ':mobile_no'            => $mapped['mobile_no'],
            ':email_address'        => $mapped['email_address'] !== '' ? $mapped['email_address'] : null,
            ':created_at'           => $createdAt,
            ':updated_at'           => $createdAt,
            ':updated_by'           => 1,
            ':is_verified'          => 1,
            ':auto_accept'          => 1,
            ':is_available'         => 1,
            ':is_online'            => 0,
            ':is_success_kyc'       => 1,
            ':status'               => 1,
            ':application_status'   => 1,
            ':ekyc_request_user_id' => $ekycRequestUserId,
        ]);
        $riderId = (int)$riderStmt->fetchColumn();

        $vehicleStmt = $pdo->prepare(
            "INSERT INTO public.rider_vehicle_details
                (rider_id, v_type, v_brand, v_model, v_color, v_plate_number, v_or_cr_img, status, created_at, updated_at)
             VALUES
                (:rider_id, :v_type, :v_brand, :v_model, :v_color, :v_plate_number, :v_or_cr_img, :status, :created_at, :updated_at)"
        );
        $vehicleStmt->execute([
            ':rider_id'       => $riderId,
            ':v_type'         => $mapped['v_type'],
            ':v_brand'        => $mapped['v_brand'],
            ':v_model'        => $mapped['v_model'],
            ':v_color'        => $mapped['v_color'],
            ':v_plate_number' => $mapped['v_plate_number'],
            ':v_or_cr_img'    => $mapped['v_or_cr_img'],
            ':status'         => 1,
            ':created_at'     => $createdAt,
            ':updated_at'     => $createdAt,
        ]);

        $addressStmt = $pdo->prepare(
            "INSERT INTO public.rider_address (rider_id, status, address, created_at, updated_at, deleted_at)
             VALUES (:rider_id, :status, :address, :created_at, :updated_at, :deleted_at)"
        );
        $addressStmt->execute([
            ':rider_id'   => $riderId,
            ':status'     => 1,
            ':address'    => $mapped['address'],
            ':created_at' => $createdAt,
            ':updated_at' => $createdAt,
            ':deleted_at' => $createdAt,
        ]);

        // -- eKYC detail row (same placeholders as the CSV import) --
        $riderAddress = $mapped['address'] !== '' ? $mapped['address'] : 'ADMIN_MISSING_ADDRESS';
        // top_ph_ekyc_details.mobile_no uses the local 0-prefixed format;
        // pretty_mobile_no keeps the 63-prefixed form.
        $mobileNoLocal = '0' . substr($mapped['mobile_no'], 2);

        $ekycStmt = $pdo->prepare(
            "INSERT INTO public.top_ph_ekyc_details
                (kyc_id, first_name, middle_name, last_name, email_address, mobile_no, pretty_mobile_no,
                 date_of_birth, place_of_birth, nationality, gender,
                 current_country, current_address, permanent_country, permanent_address,
                 nature_of_work, source_of_fund, id_type, id_number,
                 status, created_at, updated_at, generate_request_user_id)
             VALUES
                (:kyc_id, :first_name, :middle_name, :last_name, :email_address, :mobile_no, :pretty_mobile_no,
                 :date_of_birth, :place_of_birth, :nationality, :gender,
                 :current_country, :current_address, :permanent_country, :permanent_address,
                 :nature_of_work, :source_of_fund, :id_type, :id_number,
                 :status, :created_at, :updated_at, :generate_request_user_id)"
        );
        $ekycStmt->execute([
            ':kyc_id'                   => generate_uuid_v4(),
            ':first_name'               => $mapped['first_name'],
            ':middle_name'              => null,
            ':last_name'                => $mapped['last_name'],
            ':email_address'            => $mapped['email_address'] !== '' ? $mapped['email_address'] : null,
            ':mobile_no'                => $mobileNoLocal,
            ':pretty_mobile_no'         => $mapped['mobile_no'],
            ':date_of_birth'            => '01/01/1991',
            ':place_of_birth'           => 'SYSTEM_AUTOMATIC',
            ':nationality'              => 'Filipino',
            ':gender'                   => 'Male',
            ':current_country'          => 'Philippines',
            ':current_address'          => $riderAddress,
            ':permanent_country'        => 'Philippines',
            ':permanent_address'        => $riderAddress,
            ':nature_of_work'           => 'SYSTEM_AUTOMATIC',
            ':source_of_fund'           => 'Others',
            ':id_type'                  => '630000004',
            ':id_number'                => $driversLicenseNo,
            ':status'                   => 0,
            ':created_at'               => $createdAt,
            ':updated_at'               => $createdAt,
            ':generate_request_user_id' => $ekycRequestUserId,
        ]);

        // -- Wallets: one pr-user-wallet + one user-wallet per rider --
        $walletStmt = $pdo->prepare(
            "INSERT INTO public.wallet
                (ref_code, user_id, user_type, type, avail_balance, credit_amount, debit_amount, status, created_at, updated_at)
             VALUES
                (:ref_code, :user_id, :user_type, :type, 0, 0, 0, 0, :created_at, :updated_at)"
        );
        foreach (['pr-user-wallet', 'user-wallet'] as $walletType) {
            $walletStmt->execute([
                ':ref_code'   => generate_wallet_ref_code($walletSeq),
                ':user_id'    => $riderId,
                ':user_type'  => 'rider',
                ':type'       => $walletType,
                ':created_at' => $createdAt,
                ':updated_at' => $createdAt,
            ]);
            $walletSeq++;
        }

        $pdo->commit();
    } catch (PDOException $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $e;
    }

    return ['rider_id' => $riderId, 'code' => $code];
}
