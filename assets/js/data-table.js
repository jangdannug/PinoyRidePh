// Auto-initializes DataTables (https://datatables.net/) on every
// <table class="dataTable">. Include jQuery + DataTables (core and the
// bootstrap5 styling addon) before this file, then just add the class to
// any table's markup — no per-page init script needed.
//
// Add data-paging="false" on tables that already have their own
// server-side pagination (the list pages with a Filter form and a
// Previous/Next nav) so DataTables only adds client-side sort + a quick
// filter over the rows already on the page, instead of a second,
// conflicting pager.
//
// The last column is auto-detected as non-sortable whenever its header
// cell is empty — that's always a row-actions column of buttons, never
// sortable data.
$(function () {
  $('table.dataTable').each(function () {
    var $table = $(this);
    var paging = $table.data('paging') !== false;

    var columnDefs = [];
    var $lastHeader = $table.find('thead th').last();
    if ($lastHeader.length && $lastHeader.text().trim() === '') {
      columnDefs.push({ orderable: false, targets: -1 });
    }

      // Use the flat legacy dom so every feature renders as a direct child of
      // the .dt-container wrapper — initComplete then relocates each control
      // into the themed .pr-table-toolbar (or restyles it in place on pages
      // without the toolbar markup). NOTE: DataTables here is v2.1.8, which
      // uses dt-search/dt-length/dt-info/dt-paging classes (not the old
      // dataTables_* names) and .dt-container (not .dataTables_wrapper).
      $table.DataTable({
        paging: paging,
        info: paging,
        order: [],
        searching: true,
        columnDefs: columnDefs,
        dom: 'lfrtip',
        initComplete: function () {
          var $wrapper  = $table.closest('.dt-container');
          var $slot     = $table.closest('.pr-table-wrap').find('.pr-table-search-slot');
          var $count    = $table.closest('.pr-table-wrap').find('.pr-results-count');
          var $tbRight  = $table.closest('.pr-table-wrap').find('.pr-table-toolbar-right');

          // --- Search input: move into .pr-table-toolbar-left (after results count) ---
          var $search = $wrapper.find('.dt-search');
          if ($search.length) {
            // Take only the <input>, not the label (the label carries the
            // "Search:" text/magnifying-glass icon). Removing the entire
            // .dt-search div cleanly eliminates the orphaned icon.
            var $input = $search.find('input[type="search"]');
            if ($input.length && $slot.length) {
              $slot.empty().append($input);
              $input
                .addClass('pr-table-search')
                .attr('placeholder', 'Search...')
                .attr('aria-label', 'Search');
              $search.remove();
            } else if ($input.length) {
              // Non-toolbar page (no .pr-table-wrap markup): keep the native
              // search box where it is, restyled to match the theme — drop the
              // stray-icon label and give the input the pill + icon treatment.
              $search.find('label').remove();
              $input
                .addClass('pr-table-search')
                .attr('placeholder', 'Search...')
                .attr('aria-label', 'Search');
              $input.wrap('<span class="pr-table-search-slot"></span>');
            }
          }

          // --- Length selector: move into .pr-table-toolbar-right ---
          var $length = $wrapper.find('.dt-length');
          if ($length.length && $tbRight.length) {
            $tbRight.prepend($length);
            $length.find('select').addClass('form-select').css({ height: '34px', width: 'auto', padding: '4px 8px' });
          }

          // --- Pagination: wrap in gap-friendly container ---
          var $pager = $wrapper.find('.dt-paging');
          if ($pager.length) {
            $pager.wrap('<div class="pr-table-pager"></div>');
          }

          // --- Info text (Showing X to Y of Z): move into results count ---
          if (paging) {
            var $info = $wrapper.find('.dt-info');
            if ($info.length && $count.length) {
              $count.html($info.html());
              $info.remove();
            }
            // Non-toolbar page: leave the native info text in the wrapper.
          }
        },
      });
  });
});
