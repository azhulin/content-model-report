/**
 * @file
 * Content model report page.
 */

(function ($, Drupal) {

  /**
   * Handles content model report navigation.
   */
  Drupal.behaviors.contentModelReportPage = {
    attach: function () {
      $('a.reference').not('.processed').each(function () {
        $(this).addClass('processed').click(function () {
          var $target = $(this.hash);
          if ($target.length) {
            $target[0].open || $target.find('> summary').click();
            $('html, body').animate({ scrollTop: $target.offset().top }, 400);
          }
        });
      });
    }
  };

})(jQuery, Drupal);
