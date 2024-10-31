(function ($) {
  var $newdiv1 = $(''
    + '<div id="reachu_proccess_bulkaction">'
    + '<div class="reachu_proccess_bulkaction_content">'
    + '<div class="reachu_proccess_bulkaction_logo"></div>'
    + '<div class="reachu_proccess_bulkaction_progress_bar">'
    + '<div class="reachu_proccess_bulkaction_progress"></div>'
    + '</div>'
    + '<div class="reachu_proccess_bulkaction_info">'
    + '<p>Processed: <strong class="reachu_proccess_bulkaction_response">0</strong></p>'
    + '<p>Total: <strong class="reachu_proccess_bulkaction_cant">0</strong></p>'
    + '</div>'
    + '</div>'
    + '</div>');
  $("body").append($newdiv1);

  $(document).on('click', '#doaction', function (e) {
    var bulk_action = $('#bulk-action-selector-top').val();
    console.log('=> bulk_action: ', bulk_action);
    if ((bulk_action === 'reachu_sync') || (bulk_action === 'reachu_delete_prod')) {
      e.preventDefault();
      var lenghtPostId = $('input[name="post[]"]:checked').length;
      var productWithSuccess = 0;
      var productsWithError = 0;
      if (lenghtPostId) {
        var nTotal = lenghtPostId;
        $('#reachu_proccess_bulkaction .reachu_proccess_bulkaction_cant').html(lenghtPostId);
        $('#reachu_proccess_bulkaction .reachu_proccess_bulkaction_response').html('0');
        $('#reachu_proccess_bulkaction .reachu_proccess_bulkaction_progress').css('width', '0%');
        $('#reachu_proccess_bulkaction').addClass('visible');

        var idsToProcess = [];
        $("input[name='post[]']:checked").each(function () {
          idsToProcess.push($(this).val());
        });

        var chunkedIds = [];
        var chunkSize = 5;
        for (var i = 0; i < idsToProcess.length; i += chunkSize) {
          chunkedIds.push(idsToProcess.slice(i, i + chunkSize));
        }

        $.each(chunkedIds, function(index, idChunk) {
          $.ajax({
            url: dcms_vars.ajaxurl,
            type: 'post',
            data: {
              action: bulk_action,
              reachu_nonce: dcms_vars.reachu_nonce,
              id_posts: idChunk
            },
          })
          .done(function (result) {
            console.log('success result: ', result);
            productWithSuccess += idChunk.length;
          })
          .fail(function (result) {
            console.log('error result: ', result);
            productsWithError += idChunk.length;
          })
          .always(function () {
            lenghtPostId -= idChunk.length;
            $('#reachu_proccess_bulkaction .reachu_proccess_bulkaction_response').html(productWithSuccess + productsWithError);
            $('#reachu_proccess_bulkaction .reachu_proccess_bulkaction_progress').css('width', (((productWithSuccess + productsWithError) * 100) / nTotal) + '%');
            if (lenghtPostId <= 0) {
              if (bulk_action === 'reachu_sync' && productWithSuccess > 0) {
                $.ajax({
                  url: dcms_vars.ajaxurl,
                  type: 'post',
                  data: {
                    action: 'reachu_sync_finish',
                    reachu_nonce: dcms_vars.reachu_nonce,
                  },
                })
                .done(function (result) {
                  console.log(' send email success');
                });
              }
              window.location.reload();
            }
          });
        });

      }
    }
  });
})(jQuery);
