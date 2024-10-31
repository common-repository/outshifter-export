(function ($) {
  var $loader = $(''
    + '<div id="reachu_proccess_bulkaction">'
    + '<div class="reachu_proccess_bulkaction_content">'
    + '<p>Exporting products</p>'
    + '<div class="reachu_proccess_bulkaction_logo"></div>'
    + '<div class="reachu_proccess_bulkaction_progress_bar">'
    + '<div class="reachu_proccess_bulkaction_progress"></div>'
    + '</div>'
    + '<div class="reachu_proccess_bulkaction_info">'
    + '<p>Processed: <strong class="reachu_proccess_bulkaction_response">0</strong></p>'
    + '<p>Total: <strong class="reachu_proccess_bulkaction_cant">0</strong></p>'
    + '</div>'
    + '<div class="reachu_proccess_bulkaction_finish">'
    + '<div class="reachu_proccess_bulkaction_finish_ok"></div>'
    + '<div class="reachu_proccess_bulkaction_finish_error"></div>'
    + '<a id="close_finish_bulk_action_message" href="#" style="text-decoration:none;margin-top:15px;cursor:pointer">Close</a>'
    + '</div>'
    + '</div>'
    + '</div>');
  $("body").append($loader);
  $(document).on('click', '#close_finish_bulk_action_message', function (e) {
    e.preventDefault();
    $('#reachu_proccess_bulkaction').removeClass('visible');
    $('.reachu_proccess_bulkaction_finish').removeClass('visible');
    $('.reachu_proccess_bulkaction_finish_ok').html('');
    $('.reachu_proccess_bulkaction_finish_error').html('');
  });
  $(document).on('click', '#sync-all-button', function (e) {
    e.preventDefault();
    window.location.href = '/wp-admin/edit.php?post_type=product';
  });
  $(document).on('click', '#sync-all-button-disabled', function (e) {
    e.preventDefault();
  });
  $( window ).load(function() {
    $('#reachu-export-currency-btn').on('click', function (e) {
      e.preventDefault();
      $(this).prop('disabled', true);
      $(".loading").show();
      $
      $.ajax({
        url: dcms_vars.ajaxurl,
        type: 'post',
        data: {
          action: 'reachu_save_settings',
          currency: $('.default_option .option').text(),
        },
        success: function() {
          window.location.reload();
       }
      });
    });
    $('.default_option').click(function(){
      $(this).parent().toggleClass("active");
    });
    $('.select_ul li').click(function(){
      var currentele = $(this).html();
      $(".default_option li").html(currentele);
      $(this).parents(".select_wrap").removeClass("active");
    });
  });

})(jQuery);
