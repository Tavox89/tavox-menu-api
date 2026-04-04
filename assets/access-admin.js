(function () {
  'use strict';

  async function copyText(value) {
    if (!value) {
      return false;
    }

    if (navigator.clipboard && navigator.clipboard.writeText) {
      await navigator.clipboard.writeText(value);
      return true;
    }

    const input = document.createElement('input');
    input.value = value;
    document.body.appendChild(input);
    input.select();
    const copied = document.execCommand('copy');
    document.body.removeChild(input);
    return copied;
  }

  document.addEventListener('click', async function (event) {
    const trigger = event.target.closest('.tavox-copy-link');
    if (!trigger) {
      return;
    }

    event.preventDefault();

    const value = String(trigger.getAttribute('data-copy') || '').trim();
    if (!value) {
      return;
    }

    const original = trigger.textContent;

    try {
      const success = await copyText(value);
      trigger.textContent = success ? 'Copiado' : 'No se pudo copiar';
    } catch (error) {
      trigger.textContent = 'No se pudo copiar';
    }

    window.setTimeout(function () {
      trigger.textContent = original;
    }, 1600);
  });
})();
