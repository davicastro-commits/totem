<!DOCTYPE html>
<html lang="pt-BR">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=1080, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
  <meta name="theme-color" content="#FF5500">
  <meta name="mobile-web-app-capable" content="yes">
  <meta name="apple-mobile-web-app-capable" content="yes">
  <meta name="apple-mobile-web-app-status-bar-style" content="black-translucent">
  <meta name="description" content="Sistema de pedidos self-service">
  <link rel="manifest" href="manifest.json">
  <title>Totem de Pedidos</title>
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800;900&display=swap" rel="stylesheet">
  <link rel="stylesheet" href="assets/css/totem.css?v=20260622b">
</head>
<body>
  <div id="app">
    <div class="boot-screen">
      <div class="boot-spinner"></div>
    </div>
  </div>
  <div id="receipt-container" class="receipt-container hidden"></div>
  <script src="https://cdnjs.cloudflare.com/ajax/libs/qrcodejs/1.0.0/qrcode.min.js" integrity="sha512-CNgIRecGo7nphbeZ04Sc13ka07paqdeTu0WR1IM4kNcpmBAUSHSQX0FslNhTDadL4O5SAGapGt4FodqL8My0mA==" crossorigin="anonymous" referrerpolicy="no-referrer"></script>
  <script src="assets/js/totem.js?v=20260622b"></script>
  <script>
    if ('serviceWorker' in navigator) {
      window.addEventListener('load', () => {
        // Unregister old SW caches and re-register fresh
        navigator.serviceWorker.getRegistrations().then(regs => {
          regs.forEach(r => r.unregister());
        }).then(() => {
          caches.keys().then(keys => keys.forEach(k => caches.delete(k)));
        }).then(() => {
          navigator.serviceWorker.register('assets/js/sw.js?v=20260622b', { scope: '/totem/' })
            .catch(() => {});
        });
      });
    }
  </script>
</body>
</html>
