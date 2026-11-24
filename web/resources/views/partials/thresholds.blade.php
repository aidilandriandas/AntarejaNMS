<div class="thr-grid">
                @foreach(\App\Models\Device::THRESHOLDS as $k => [$label, $unit, $def, $type])
                    @php($cur = $values[$k] ?? null)
                    @php($inh = $inherited[$k] ?? null)
                    <div class="field"><label>{{ $label }} <span class="muted">({{ $unit }})</span></label>
                        @if($type === 'single')
                            <input class="input" type="number" step="any" name="thr[{{ $k }}]" value="{{ old("thr.$k", $cur) }}" placeholder="{{ $inh !== null ? 'templat ' . (is_array($inh) ? implode(' / ', $inh) : $inh) : 'bawaan ' . $def }}">
                        @else
                            <div class="row" style="gap:6px">
                                <input class="input" type="number" step="any" name="thr[{{ $k }}][warn]" value="{{ old("thr.$k.warn", $cur[0] ?? null) }}" placeholder="peringatan" aria-label="{{ $label }} peringatan">
                                <input class="input" type="number" step="any" name="thr[{{ $k }}][crit]" value="{{ old("thr.$k.crit", $cur[1] ?? null) }}" placeholder="kritis" aria-label="{{ $label }} kritis">
                            </div><div class="hint">{{ $inh !== null ? 'templat ' . implode(' / ', (array) $inh) : 'bawaan ' . $def }}</div>
                        @endif
                        <x-field-error :name="'thr.'.$k"/></div>
                @endforeach
            </div>
