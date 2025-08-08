jQuery(document).ready(function ($) {
  $('.toggle-sp-cert').on('click', function () {
    $('.sp-cert-field').toggle();
  });

  $('.toggle-private-key').on('click', function () {
    $('.private-key-field').toggle();
  });
});
