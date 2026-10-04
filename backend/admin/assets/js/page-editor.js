/* Page editor: Summernote with server-side image uploads */
(function ($) {
  'use strict';
  // Wait for DOM ready: Bootstrap 5 registers the jQuery tooltip plugin Summernote needs only then
  $(function () {
  const $editor = $('#content');
  if (!$editor.length || !$.fn.summernote) return;

  $editor.summernote({
    height: 420,
    placeholder: 'Write the page content…',
    toolbar: [
      ['style', ['style']],
      ['font', ['bold', 'italic', 'underline', 'clear']],
      ['para', ['ul', 'ol', 'paragraph']],
      ['insert', ['link', 'picture', 'table', 'hr']],
      ['view', ['codeview', 'help']],
    ],
    styleTags: ['p', 'h2', 'h3', 'h4', 'blockquote'],
    callbacks: {
      // Upload images to the server instead of embedding them as huge base64 strings
      onImageUpload(files) {
        Array.from(files).forEach((file) => {
          const fd = new FormData();
          fd.append('image', file);
          $.ajax({ url: 'ajax/editor-upload.php', method: 'POST', data: fd, processData: false, contentType: false })
            .done((res) => $editor.summernote('insertImage', res.data.url, (img) => img.attr('alt', file.name.replace(/\.[^.]+$/, ''))))
            .fail((xhr) => VC.toast((xhr.responseJSON && xhr.responseJSON.message) || 'Image upload failed.', 'danger'));
        });
      },
      // Paste as clean text paragraphs (strips Word styles)
      onPaste(e) {
        const clip = (e.originalEvent || e).clipboardData;
        if (!clip || clip.types.includes('Files')) return;
        e.preventDefault();
        const text = clip.getData('text/plain');
        const html = text.split(/\n{2,}/).map((p) => `<p>${VC.escapeHtml(p).replace(/\n/g, '<br>')}</p>`).join('');
        document.execCommand('insertHTML', false, html);
      },
    },
  });

  // Make sure code view edits are saved too
  $('#pageForm').on('submit', () => {
    if ($editor.summernote('codeview.isActivated')) $editor.summernote('codeview.deactivate');
  });
  });
})(jQuery);
