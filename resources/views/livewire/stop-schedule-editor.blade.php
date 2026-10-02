<div>
    @foreach ($cases as $case)
        @php($key = $case->value)
        <section class="card" wire:key="card-{{ $key }}">
            <h2>{{ $case->label() }} stops</h2>

            @foreach ($directions as $direction)
                @php($dir = $direction->value)
                @php($current = $saved[$key][$dir] ?? null)
                <div class="direction" wire:key="direction-{{ $key }}-{{ $dir }}">
                    <h3>{{ $direction->label() }}</h3>

                    <p class="current">
                        @if ($current)
                            {{ $current->name }}@if ($current->stopLetter), Stop {{ $current->stopLetter }}@endif
                            @if ($current->towards)
                                <span class="muted">towards {{ $current->towards }}</span>
                            @endif
                        @else
                            <span class="muted">No stop set</span>
                        @endif
                    </p>

                    <form wire:submit="search('{{ $key }}', '{{ $dir }}')" class="row">
                        <input type="search" wire:model="query.{{ $key }}.{{ $dir }}" placeholder="Search stop name" aria-label="Search {{ $key }} {{ $dir }} stop">
                        <button type="submit">
                            <span wire:loading.remove wire:target="search('{{ $key }}', '{{ $dir }}')">Search</span>
                            <span wire:loading wire:target="search('{{ $key }}', '{{ $dir }}')">Searching…</span>
                        </button>
                    </form>

                    @if ($problem[$key][$dir])
                        <p class="error" role="alert">{{ $problem[$key][$dir] }}</p>
                    @endif

                    @if ($notice[$key][$dir])
                        <p class="notice" role="status">{{ $notice[$key][$dir] }}</p>
                    @endif

                    @if ($stops[$key][$dir])
                        <p class="hint" role="status">
                            Found {{ count($stops[$key][$dir]) }} {{ Str::plural('stop', count($stops[$key][$dir])) }} for "{{ $searched[$key][$dir] }}". Pick the one you wait at:
                        </p>
                        <ul class="choices" wire:loading.class="busy" wire:target="chooseStop">
                            @foreach ($stops[$key][$dir] as $stop)
                                <li wire:key="stop-{{ $key }}-{{ $dir }}-{{ $stop['naptan_id'] }}">
                                    <button type="button" wire:click="chooseStop('{{ $key }}', '{{ $dir }}', @js($stop['naptan_id']))">
                                        {{ $stop['name'] }}@if ($stop['stop_letter']), <strong>Stop {{ $stop['stop_letter'] }}</strong>@endif
                                        @if ($stop['towards'])
                                            <span class="muted">towards {{ $stop['towards'] }}</span>
                                        @endif
                                    </button>
                                </li>
                            @endforeach
                        </ul>
                        <p class="actions">
                            <button type="button" class="plain" wire:click="cancel('{{ $key }}', '{{ $dir }}')">Clear</button>
                        </p>
                    @endif
                </div>
            @endforeach
        </section>
    @endforeach

    <section class="card">
        <h2>R1 link</h2>
        <code>{{ $deviceUrl }}</code>
        <button type="button" class="plain" wire:click="regenerateToken"
            wire:confirm="The current R1 link will stop working. Continue?">Regenerate</button>
    </section>
</div>
