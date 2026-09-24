import Alpine from 'alpinejs';
import collapse from '@alpinejs/collapse';
import './charts';
import './toast';

Alpine.plugin(collapse);
window.Alpine = Alpine;
Alpine.start();
