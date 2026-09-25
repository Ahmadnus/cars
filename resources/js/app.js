import Alpine from 'alpinejs';
import collapse from '@alpinejs/collapse';
import './charts';
import './toast';
import { enablePush, startNotificationPolling } from './notifications';

Alpine.plugin(collapse);
window.Alpine = Alpine;
Alpine.start();

// Exposed so the bell's "enable notifications" button can call it from Blade:
// the permission prompt has to follow a click, or browsers deny it outright.
window.enablePush = enablePush;

startNotificationPolling();
