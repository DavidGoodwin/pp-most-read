jQuery(document).ready(function($) {
    $.post(
        PPMostRead.ajaxurl,
        {
            action:      PPMostRead.action,
            postID:      PPMostRead.postid,
            _ajax_nonce: PPMostRead.nonce
        },
        function(result) {
            return;
        }
    );
});
