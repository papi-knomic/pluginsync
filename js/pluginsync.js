jQuery(document).ready(function ($) {
	$('#pluginsync-export').on('click', function () {
		$.ajax({
			url: pluginsyncAjax.ajax_url,
			type: 'POST',
			data: {
				action: 'pluginsync_export',
				_ajax_nonce: pluginsyncAjax.nonce
			},
			success: function (response) {
				if (response.success) {
					const dataStr = "data:text/json;charset=utf-8," + encodeURIComponent(JSON.stringify(response.data));
					const downloadAnchor = document.createElement('a');
					downloadAnchor.setAttribute("href", dataStr);
					downloadAnchor.setAttribute("download", "pluginsync.json");
					document.body.appendChild(downloadAnchor);
					downloadAnchor.click();
					downloadAnchor.remove();
				} else {
					alert('Error: ' + response.data);
				}
			},
			error: function () {
				alert('There was an error exporting plugins.');
			}
		});
	});

	$('#pluginsync-import').on('click', function () {
		const fileInput = $('#pluginsync-import-file')[0];

		if (fileInput.files.length === 0) {
			alert('Please select a file to import.');
			return;
		}

		const formData = new FormData();
		formData.append('action', 'pluginsync_import');
		formData.append('file', fileInput.files[0]);
		formData.append('_ajax_nonce', pluginsyncAjax.nonce);

		$.ajax({
			url: pluginsyncAjax.ajax_url,
			type: 'POST',
			data: formData,
			processData: false,
			contentType: false,
			success: function (response) {
				if (response.success) {
					alert(response.data);
				} else {
					alert('Error: ' + response.data);
				}
			},
			error: function () {
				alert('There was an error importing plugins.');
			}
		});
	});
});