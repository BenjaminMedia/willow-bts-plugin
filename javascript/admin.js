jQuery(document).ready(function($) {
	$('body').on('click', '#bts-form-submit', function(e) {
		e.preventDefault();

		var t = $(this);

		// fetching form data related to the current post
		var form_data = $('#post').serializeArray().filter(function(elm) {
			// returning current post id
			if (elm.name === 'post_ID') {
				return true;
			}
			// returning all bts elements as well
			return elm.name.startsWith('bts_');
		});

		var data = {};
		// we need to flatten the data properly, so PHP can understand it
		for (var i = 0; i < form_data.length; i++){
			data[form_data[i]['name']] = form_data[i]['value'];
		}

		// adding the action to the data as well
		$.extend(data, {
			action: 'bts_translate_request_action',
		});

		$.post(ajaxurl, data, function(response) {
			if(response.status != 200) {
				//TODO: Add Error handling (Wrong nonce, no permissions, …) here.
			} else {
				//TODO: Do stuff with your response (manipulate DOM, alert user, …)

				// clearing the fields
				$('#js-bts_comment').val('');
				$('#js-bts_deadline').val('');

				$('.bts-language-checkbox').each(function (index, checkbox) {
					console.log(checkbox);
					// resetting checkbox state.
					$(checkbox).prop('checked', false);
				});

				// updating language states
				$.each(response.article.languages, function (index, langauge) {
					var state = langauge.state;

					// adding () to the state
					if (state != null && state.length > 0) {
						state = '(' + state + ')';
					} else {
						state = '';
					}

					$('.js-bts-status[data-language="'+langauge.code+'"]').text(state);
				});

				// flashing a status message to the user
				$('#js-bts-form-submit-status').text('Sent!');
				setTimeout(function() {
					$('#js-bts-form-submit-status').text('');
				}, 2000);
			}
		}, 'json');
	});
});