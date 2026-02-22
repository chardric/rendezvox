/* ============================================================
   Scroll Controls — mouse wheel support for range & number inputs
   ============================================================
   Include this script on any page. It uses event delegation so
   dynamically created inputs are automatically handled.

   - Range inputs (sliders): scroll adjusts value by 1-5% of range
   - Number inputs: scroll adjusts by step value (default 1)
   - Only activates when the input is hovered (not focused elsewhere)
   - Prevents page scroll while adjusting
   ============================================================ */
(function() {
  document.addEventListener('wheel', function(e) {
    var el = e.target;
    if (!el || !el.matches('input[type=range], input[type=number]')) return;

    e.preventDefault();

    var dir = e.deltaY < 0 ? 1 : -1;

    if (el.type === 'range') {
      var min  = parseFloat(el.min) || 0;
      var max  = parseFloat(el.max) || 100;
      var step = parseFloat(el.step) || 1;
      var bump = Math.max(step, (max - min) * 0.03);
      var val  = parseFloat(el.value) + (dir * bump);
      el.value = Math.min(max, Math.max(min, val));
      el.dispatchEvent(new Event('input', { bubbles: true }));
    }

    if (el.type === 'number') {
      var step2 = parseFloat(el.step) || 1;
      var min2  = el.min !== '' ? parseFloat(el.min) : -Infinity;
      var max2  = el.max !== '' ? parseFloat(el.max) : Infinity;
      var val2  = (parseFloat(el.value) || 0) + (dir * step2);
      // Round to avoid floating point drift
      var decimals = (step2.toString().split('.')[1] || '').length;
      val2 = parseFloat(val2.toFixed(decimals));
      el.value = Math.min(max2, Math.max(min2, val2));
      el.dispatchEvent(new Event('input', { bubbles: true }));
      el.dispatchEvent(new Event('change', { bubbles: true }));
    }
  }, { passive: false });
})();
