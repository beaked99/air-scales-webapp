// assets/app.js
// Import main CSS (Tailwind only - Flowbite styles come via plugin)
import './styles/app.css';

// Import Flowbite JavaScript for interactive components
import 'flowbite';

// Optional: import Stimulus if you're using it
import './bootstrap.js';

// Import Bluetooth module - initializes on every page
import './js/ble.js';

// Initialize Bluetooth when in Capacitor
if (window.Capacitor && window.Capacitor.isNativePlatform()) {
  window.AirScalesBLE.init().then(success => {
    if (success) {
      console.log('🔵 Bluetooth ready');
    }
  });
}

if (window.location.pathname.startsWith('/app')) {
  import('./app/register-sw.js');
}

console.log('Tailwind + Flowbite loaded via Encore ✅');

// Initialize BLE when running as native app
document.addEventListener('DOMContentLoaded', async () => {
  if (window.AirScalesBLE) {
    console.log('🔵 Initializing AirScalesBLE...');
    const success = await window.AirScalesBLE.init();
    if (success) {
      console.log('✅ Bluetooth ready');
    } else {
      console.log('⚠️ Bluetooth not available (web browser or init failed)');
    }
  }
});