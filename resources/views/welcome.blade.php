<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Dispatch — AI agents for your GitHub repos</title>
    <link rel="icon" href="/favicon.ico" sizes="any">
    <link rel="icon" href="/favicon.svg" type="image/svg+xml">
    <link rel="apple-touch-icon" href="/apple-touch-icon.png">
    <link rel="preconnect" href="https://fonts.bunny.net">
    <link href="https://fonts.bunny.net/css?family=instrument-sans:400,500,600|geist-mono:400" rel="stylesheet" />
    @vite(['resources/css/app.css', 'resources/js/app.js'])
    <script>
        if (window.matchMedia('(prefers-color-scheme: dark)').matches) {
            document.documentElement.classList.add('dark');
        } else {
            document.documentElement.classList.remove('dark');
        }
    </script>
</head>
<body class="bg-white dark:bg-zinc-900 text-zinc-900 dark:text-zinc-100 font-[Instrument_Sans] antialiased">

    {{-- Nav --}}
    <nav class="max-w-3xl mx-auto px-6 py-4 flex items-center justify-between">
        <a href="/" class="text-lg font-semibold tracking-tight">Dispatch</a>
        <div class="flex items-center gap-4 text-sm">
            <a href="https://github.com/datashaman/dispatch" class="text-zinc-500 hover:text-zinc-900 dark:hover:text-zinc-100 transition-colors duration-150">GitHub</a>
            @unless(\App\Http\Middleware\LaunchLockdown::isActive(request()))
                @auth
                    <a href="{{ route('dashboard') }}" class="inline-flex items-center px-3 py-1.5 rounded-md bg-indigo-500 text-white text-sm font-medium hover:bg-indigo-400 transition-colors duration-150">Dashboard</a>
                @else
                    <a href="{{ route('login') }}" class="inline-flex items-center px-3 py-1.5 rounded-md bg-indigo-500 text-white text-sm font-medium hover:bg-indigo-400 transition-colors duration-150">Log in</a>
                @endauth
            @endunless
        </div>
    </nav>

    {{-- Header --}}
    <header class="max-w-3xl mx-auto px-6 pt-8 pb-6">
        <h1 class="text-3xl font-semibold tracking-tight leading-tight">GitHub Actions, but the actions are AI agents</h1>
        <p class="mt-3 text-base text-zinc-600 dark:text-zinc-400 max-w-xl">Define rules in YAML. Connect your repo. AI agents handle the rest — triaging issues, reviewing code, responding to discussions.</p>
        <div class="mt-5 flex items-center gap-3">
            @if(\App\Http\Middleware\LaunchLockdown::isActive(request()))
                {{-- Email subscribe form for locked-down visitors --}}
                @if(session('subscribed'))
                    <p class="text-sm text-emerald-600 dark:text-emerald-400 font-medium">Thanks! We'll notify you when we launch.</p>
                @else
                    <form action="{{ route('subscribe') }}" method="POST" class="flex items-center gap-2">
                        @csrf
                        <input type="email" name="email" required placeholder="you@example.com" class="px-3 py-2 rounded-md border border-zinc-300 dark:border-zinc-700 bg-white dark:bg-zinc-800 text-sm text-zinc-900 dark:text-zinc-100 placeholder-zinc-400 focus:outline-none focus:ring-2 focus:ring-indigo-500 focus:border-transparent" />
                        <button type="submit" class="inline-flex items-center px-4 py-2 rounded-md bg-indigo-500 text-white text-sm font-medium hover:bg-indigo-400 transition-colors duration-150">Notify me</button>
                    </form>
                    @error('email')
                        <p class="text-sm text-red-500">{{ $message }}</p>
                    @enderror
                @endif
            @else
                @auth
                    <a href="{{ route('dashboard') }}" class="inline-flex items-center px-4 py-2 rounded-md bg-indigo-500 text-white text-sm font-medium hover:bg-indigo-400 transition-colors duration-150">Get Started</a>
                @else
                    <a href="{{ route('login') }}" class="inline-flex items-center px-4 py-2 rounded-md bg-indigo-500 text-white text-sm font-medium hover:bg-indigo-400 transition-colors duration-150">Get Started</a>
                @endauth
            @endif
            <a href="https://github.com/datashaman/dispatch" class="inline-flex items-center px-4 py-2 rounded-md border border-zinc-300 dark:border-zinc-700 text-sm font-medium text-zinc-700 dark:text-zinc-300 hover:bg-zinc-100 dark:hover:bg-zinc-800 transition-colors duration-150">View on GitHub</a>
        </div>
    </header>

    {{-- dispatch.yml code block --}}
    <section class="max-w-3xl mx-auto px-6 py-8">
        <div class="rounded-lg border border-zinc-200 dark:border-zinc-800 bg-zinc-50 dark:bg-zinc-900 overflow-hidden">
            <div class="px-4 py-2 border-b border-zinc-200 dark:border-zinc-800 text-xs font-[Geist_Mono] text-zinc-500">dispatch.yml</div>
            <pre class="px-4 py-4 text-sm font-[Geist_Mono] leading-relaxed overflow-x-auto"><code><span class="text-zinc-500"># dispatch.yml</span>
<span class="text-indigo-600 dark:text-indigo-400">rules</span><span class="text-zinc-700 dark:text-zinc-300">:</span>
  - <span class="text-indigo-600 dark:text-indigo-400">name</span><span class="text-zinc-700 dark:text-zinc-300">:</span> <span class="text-zinc-700 dark:text-zinc-300">triage-issues</span>
    <span class="text-indigo-600 dark:text-indigo-400">on</span><span class="text-zinc-700 dark:text-zinc-300">:</span> <span class="text-zinc-700 dark:text-zinc-300">issues.opened</span>
    <span class="text-indigo-600 dark:text-indigo-400">prompt</span><span class="text-zinc-700 dark:text-zinc-300">:</span> <span class="text-zinc-700 dark:text-zinc-300">|</span>
      <span class="text-zinc-700 dark:text-zinc-300">Read the issue title and body.</span>
      <span class="text-zinc-700 dark:text-zinc-300">Add appropriate labels and post</span>
      <span class="text-zinc-700 dark:text-zinc-300">a summary comment with next steps.</span></code></pre>
        </div>
    </section>

    {{-- How it works --}}
    <section class="max-w-3xl mx-auto px-6 py-8">
        <h2 class="text-xl font-semibold tracking-tight mb-6">How it works</h2>
        <div class="flex flex-col gap-4">
            <div class="flex items-start gap-4">
                <span class="font-[Geist_Mono] text-sm font-medium text-indigo-500 pt-0.5">01</span>
                <p class="text-base text-zinc-700 dark:text-zinc-300">Add <code class="font-[Geist_Mono] text-sm bg-zinc-100 dark:bg-zinc-800 px-1.5 py-0.5 rounded">dispatch.yml</code> to your repo</p>
            </div>
            <div class="flex items-start gap-4">
                <span class="font-[Geist_Mono] text-sm font-medium text-indigo-500 pt-0.5">02</span>
                <p class="text-base text-zinc-700 dark:text-zinc-300">Connect via GitHub App</p>
            </div>
            <div class="flex items-start gap-4">
                <span class="font-[Geist_Mono] text-sm font-medium text-indigo-500 pt-0.5">03</span>
                <p class="text-base text-zinc-700 dark:text-zinc-300">Agents do the work</p>
            </div>
        </div>
    </section>

    {{-- Footer --}}
    <footer class="max-w-3xl mx-auto px-6 py-8">
        <p class="text-center text-sm text-zinc-500">Self-hosted. Open source. Your keys, your infrastructure.</p>
    </footer>

</body>
</html>
