@props([
    /** Count-only endpoint to poll. */
    'url',
    /** The count as the page was rendered; arrivals are measured against it. */
    'current' => 0,
    /** JSON key holding the count. */
    'key' => 'pending',
    'message' => 'وصل :count طلب جديد.',
    'action' => 'عرض الطلبات',
    'seconds' => 10,
])

{{--
    Tells a queue page that something arrived.

    Polls a count rather than the rows: the list is already on screen, and sending
    it again every few seconds would carry every applicant's personal details with
    it. Nothing reloads on its own either — a reviewer part-way through typing a
    rejection reason would lose it — so the page offers the reload and lets them
    take it when ready.

    Not a substitute for a socket. When a realtime provider is configured this
    becomes redundant; until then it is what makes the queue feel attended.
--}}
<div x-data="{
         known: {{ (int) $current }},
         arrived: 0,
         timer: null,

         start() {
             this.stop();
             this.timer = setInterval(() => this.check(), {{ (int) $seconds * 1000 }});
         },

         stop() {
             if (this.timer) {
                 clearInterval(this.timer);
                 this.timer = null;
             }
         },

         async check() {
             try {
                 const response = await fetch(@js($url), {
                     headers: { Accept: 'application/json' },
                     credentials: 'same-origin',
                 });

                 if (!response.ok) return;

                 const payload = await response.json();
                 const count = payload[@js($key)] ?? this.known;

                 if (count > this.known) {
                     this.arrived = count - this.known;
                     window.toast?.(@js(str_replace(':count', '', $message)).trim(), 'info');
                 }
             } catch (error) {
                 /* Offline for a moment; the next tick covers it. */
             }
         },
     }"
     x-init="start()"
     {{-- A timer firing behind a hidden tab is a request nobody reads. --}}
     x-on:visibilitychange.document="document.hidden ? stop() : (check(), start())"
     {{ $attributes->merge(['class' => 'mb-4']) }}>

    <div x-show="arrived > 0" x-cloak x-transition
         class="flex flex-wrap items-center justify-between gap-3 rounded-xl border border-amber-200 bg-amber-50 px-4 py-3">
        <p class="flex items-center gap-2 text-sm font-medium text-amber-800">
            <span class="relative flex size-2">
                <span class="absolute inline-flex size-full animate-ping rounded-full bg-amber-500 opacity-75"></span>
                <span class="relative inline-flex size-2 rounded-full bg-amber-600"></span>
            </span>
            <span x-text="@js($message).replace(':count', arrived)"></span>
        </p>

        <x-ui.button size="sm" @click="window.location.reload()">{{ $action }}</x-ui.button>
    </div>
</div>
