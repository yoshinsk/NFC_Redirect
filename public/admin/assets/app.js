/**
 * ファイル: public/admin/assets/app.js
 * 機能: NFC URLのコピーと、削除確認モーダルへ対象情報を安全に設定する。
 */

document.querySelectorAll('.copy-url').forEach((button) => {
  button.addEventListener('click', async () => {
    const originalLabel = button.textContent;
    try {
      await navigator.clipboard.writeText(button.dataset.copyText || '');
      button.textContent = 'コピー済み';
    } catch (error) {
      button.textContent = 'コピーできません';
    }
    window.setTimeout(() => { button.textContent = originalLabel; }, 1600);
  });
});

const deleteModal = document.getElementById('deleteModal');
if (deleteModal) {
  deleteModal.addEventListener('show.bs.modal', (event) => {
    const trigger = event.relatedTarget;
    if (!(trigger instanceof HTMLElement)) return;

    document.getElementById('deleteId').value = trigger.dataset.id || '';
    document.getElementById('deleteConfirmationSlug').value = trigger.dataset.slug || '';
    document.getElementById('deleteSlug').textContent = trigger.dataset.slug || '';
    document.getElementById('deleteCount').textContent = trigger.dataset.count || '0';
  });
}
