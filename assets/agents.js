/* Your agents: choosing an agent from the pull-down shows it at once. Without this script the Show button does the same. */
(function () {
  var form = document.querySelector('.agent-picker');
  if (!form) return;
  var select = form.querySelector('select'), go = form.querySelector('.picker-go');
  if (go) go.hidden = true;
  select.addEventListener('change', function () { form.submit(); });
})();
