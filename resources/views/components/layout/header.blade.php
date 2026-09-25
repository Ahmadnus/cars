@php
    $user = auth()->user();
    $branches = branch_context()->selectable();
    $current = branch_context()->current();
    $unread = $user?->unreadNotifications()->count() ?? 0;
@endphp

<header class="sticky top-0 z-20 flex h-16 items-center gap-3 border-b border-ink-200 bg-white/90 px-4 backdrop-blur sm:px-6 lg:px-8">
    <button type="button" @click="sidebarOpen = true"
            class="rounded-md p-2 text-ink-500 hover:bg-ink-100 lg:hidden">
        <x-ui.icon name="menu" class="size-5" />
    </button>

    <div class="min-w-0 flex-1">
        <h1 class="truncate text-base font-semibold text-ink-900">@yield('title', 'لوحة التحكم')</h1>
        @hasSection('subtitle')
            <p class="truncate text-xs text-ink-400">@yield('subtitle')</p>
        @endif
    </div>

    {{-- Branch selector: only lists branches this user is actually granted --}}
    @if ($branches->count() > 1 || $user?->canAccessAllBranches())
        <div x-data="{ open: false }" class="relative">
            <button type="button" @click="open = !open" @click.outside="open = false"
                    class="flex items-center gap-2 rounded-lg border border-ink-200 px-3 py-2 text-sm text-ink-700 hover:bg-ink-50">
                <x-ui.icon name="building" class="size-4 text-ink-400" />
                <span class="hidden max-w-36 truncate sm:inline">{{ $current?->name ?? 'جميع الفروع' }}</span>
                <x-ui.icon name="chevron-down" class="size-4 text-ink-400" />
            </button>

            <div x-show="open" x-transition x-cloak
                 class="absolute z-30 mt-2 w-60 overflow-hidden rounded-xl border border-ink-200 bg-white py-1 shadow-lg ltr:right-0 rtl:left-0">
                @if ($user?->canAccessAllBranches())
                    <form method="POST" action="{{ route('admin.branch.switch') }}">
                        @csrf
                        <input type="hidden" name="branch_id" value="">
                        <button type="submit"
                                @class(['flex w-full items-center gap-2 px-3 py-2 text-sm hover:bg-ink-50',
                                        'font-semibold text-brand-700' => ! $current])>
                            <x-ui.icon name="globe" class="size-4" /> جميع الفروع
                        </button>
                    </form>
                    <div class="my-1 border-t border-ink-100"></div>
                @endif

                @foreach ($branches as $branch)
                    <form method="POST" action="{{ route('admin.branch.switch') }}">
                        @csrf
                        <input type="hidden" name="branch_id" value="{{ $branch->id }}">
                        <button type="submit"
                                @class(['flex w-full items-center justify-between px-3 py-2 text-sm hover:bg-ink-50',
                                        'font-semibold text-brand-700' => $current?->id === $branch->id])>
                            <span class="truncate">{{ $branch->name }}</span>
                            <span class="text-xs text-ink-400">{{ $branch->code }}</span>
                        </button>
                    </form>
                @endforeach
            </div>
        </div>
    @elseif ($current)
        <span class="hidden items-center gap-2 rounded-lg border border-ink-200 px-3 py-2 text-sm text-ink-600 sm:flex">
            <x-ui.icon name="building" class="size-4 text-ink-400" />{{ $current->name }}
        </span>
    @endif

    {{--
        The bell keeps itself current.

        `count` starts from the server-rendered value so the badge is right on
        first paint, then the poll and browser push both update it — a
        receptionist should not have to reload to learn a booking request came in.
    --}}
    <div x-data="{
             count: {{ (int) $unread }},
             pushState: (window.Notification && Notification.permission) || 'default',
             async enable() {
                 const result = await window.enablePush();

                 this.pushState = result.ok
                     ? 'granted'
                     : (result.reason === 'denied' ? 'denied' : 'unavailable');
             },
         }"
         x-on:dashboard:notifications.window="count = $event.detail.unread"
         x-on:dashboard:push.window="window.toast?.($event.detail.title, 'info')"
         class="flex items-center gap-1">

        <a href="{{ route('admin.notifications.index') }}"
           class="relative rounded-lg p-2 text-ink-500 hover:bg-ink-100" title="الإشعارات">
            <x-ui.icon name="bell" class="size-5" />
            <span x-show="count > 0" x-cloak
                  class="absolute top-1 rounded-full bg-rose-500 px-1.5 text-[10px] font-bold text-white ltr:right-1 rtl:left-1"
                  x-text="count > 99 ? '99+' : count"></span>
        </a>

        {{-- Shown only until permission is settled: a browser will not re-prompt
             after a denial, so nagging past that point is pointless. --}}
        <button type="button" x-show="pushState === 'default'" x-cloak @click="enable()"
                class="rounded-lg p-2 text-ink-400 hover:bg-ink-100 hover:text-brand-600"
                title="تشغيل إشعارات المتصفح">
            <x-ui.icon name="bell-plus" class="size-5" />
        </button>
    </div>

    <div x-data="{ open: false }" class="relative">
        <button type="button" @click="open = !open" @click.outside="open = false"
                class="flex items-center gap-2 rounded-lg p-1.5 hover:bg-ink-100">
            <span class="flex size-8 items-center justify-center rounded-full bg-brand-100 text-xs font-semibold text-brand-700">
                {{ $user?->initials() }}
            </span>
            <span class="hidden text-sm text-ink-700 sm:inline">{{ $user?->name }}</span>
            <x-ui.icon name="chevron-down" class="size-4 text-ink-400" />
        </button>

        <div x-show="open" x-transition x-cloak
             class="absolute z-30 mt-2 w-56 overflow-hidden rounded-xl border border-ink-200 bg-white py-1 shadow-lg ltr:right-0 rtl:left-0">
            <div class="border-b border-ink-100 px-3 py-2">
                <p class="truncate text-sm font-medium text-ink-900">{{ $user?->name }}</p>
                <p class="truncate text-xs text-ink-400">{{ $user?->email }}</p>
                <p class="mt-1 text-xs text-brand-600">{{ $user?->roles->pluck('label_ar')->join('، ') ?: 'بدون دور' }}</p>
            </div>

            <a href="{{ route('admin.profile.edit') }}" class="flex items-center gap-2 px-3 py-2 text-sm text-ink-700 hover:bg-ink-50">
                <x-ui.icon name="user-cog" class="size-4 text-ink-400" /> الملف الشخصي
            </a>

            <form method="POST" action="{{ route('logout') }}">
                @csrf
                <button type="submit" class="flex w-full items-center gap-2 px-3 py-2 text-sm text-rose-600 hover:bg-rose-50">
                    <x-ui.icon name="logout" class="size-4" /> تسجيل الخروج
                </button>
            </form>
        </div>
    </div>
</header>
