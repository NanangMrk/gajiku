(function($) {
  'use strict';
  $(function() {
    function openSidebar() {
      $('.sidebar-offcanvas').addClass('active');
      $('#sidebarBackdrop').addClass('active');
      $('body').addClass('sidebar-mobile-open');
    }

    function closeSidebar() {
      $('.sidebar-offcanvas').removeClass('active');
      $('#sidebarBackdrop').removeClass('active');
      $('body').removeClass('sidebar-mobile-open');
    }

    function toggleSidebar() {
      if ($('.sidebar-offcanvas').hasClass('active')) {
        closeSidebar();
      } else {
        openSidebar();
      }
    }

    // Expose globally if needed
    window.openMobileSidebar = openSidebar;
    window.closeMobileSidebar = closeSidebar;
    window.toggleMobileSidebar = toggleSidebar;

    // Toggle button handler
    $(document).on("click", '[data-toggle="offcanvas"]', function(e) {
      if (e) {
        e.preventDefault();
        e.stopPropagation();
      }
      toggleSidebar();
    });

    // Backdrop click handler
    $(document).on("click", '#sidebarBackdrop', function(e) {
      if (e) e.preventDefault();
      closeSidebar();
    });

    // Auto-close on nav link click in mobile view
    $(document).on("click", '.sidebar-offcanvas .nav-link:not([data-bs-toggle])', function() {
      if (window.innerWidth < 992) {
        closeSidebar();
      }
    });

    // Close on Escape key
    $(document).on("keydown", function(e) {
      if (e.key === "Escape" && $('.sidebar-offcanvas').hasClass('active')) {
        closeSidebar();
      }
    });
  });
})(jQuery);