<div>
    @foreach ($cases as $case)
        @php($key = $case->value)
        <section class="card" wire:key="card-{{ $key }}">
            <h2>{{ $case->label() }} stop</h2>

            <p class="current">
                @if ($saved->has($key))
                    {{ $saved[$key]->name }}@if ($saved[$key]->stop_letter), Stop {{ $saved[$key]->stop_letter }}@endif
                    @if ($saved[$key]->towards)
                        <span class="muted">towards {{ $saved[$key]->towards }}</span>
                    @endif
                @else
                    <span class="muted">No stop set</span>
                @endif
            </p>

            <form wire:submit="search('{{ $key }}')" class="row">
                <input type="search" wire:model="query.{{ $key }}" placeholder="Search stop name" aria-label="Search {{ $key }} stop">
                <button type="submit">Search</button>
            </form>

            @if ($problem[$key])
                <p class="error">{{ $problem[$key] }}</p>
            @endif

            @if ($stops[$key])
                <ul class="choices">
                    @foreach ($stops[$key] as $stop)
                        <li wire:key="stop-{{ $key }}-{{ $stop['naptan_id'] }}">
                            <button type="button" wire:click="chooseStop('{{ $key }}', @js($stop['naptan_id']))">
                                {{ $stop['name'] }}@if ($stop['stop_letter']), Stop {{ $stop['stop_letter'] }}@endif
                                @if ($stop['towards'])
                                    <span class="muted">towards {{ $stop['towards'] }}</span>
                                @endif
                            </button>
                        </li>
                    @endforeach
                </ul>
            @elseif ($places[$key])
                <ul class="choices">
                    @foreach ($places[$key] as $place)
                        <li wire:key="place-{{ $key }}-{{ $place['id'] }}">
                            <button type="button" wire:click="choosePlace('{{ $key }}', @js($place['id']))">
                                {{ $place['name'] }}
                                @if ($place['towards'])
                                    <span class="muted">towards {{ $place['towards'] }}</span>
                                @endif
                            </button>
                        </li>
                    @endforeach
                </ul>
            @endif
        </section>
    @endforeach

    <section class="card">
        <h2>R1 link</h2>
        <code>{{ $deviceUrl }}</code>
        <button type="button" class="plain" wire:click="regenerateToken"
            wire:confirm="The current R1 link will stop working. Continue?">Regenerate</button>
    </section>
</div>
