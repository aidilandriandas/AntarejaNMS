{{-- Isian layanan. $s = Service|null --}}
@php($sitesList = \App\Models\Site::orderBy('code')->get(['id', 'code', 'name']))
<div class="field"><label for="sv-name">Nama layanan</label><input class="input @error('name') is-err @enderror" id="sv-name" name="name" value="{{ old('name', $s?->name) }}" maxlength="120" required placeholder="mis. CCTV Pit A"><x-field-error name="name"/></div>
<div class="field"><label for="sv-desc">Keterangan <span class="muted">(opsional)</span></label><input class="input" id="sv-desc" name="description" value="{{ old('description', $s?->description) }}" maxlength="500" placeholder="mis. Pemantauan area loading Pit A"></div>
<div class="field"><label for="sv-site">Site <span class="muted">(opsional)</span></label><select class="input" id="sv-site" name="site_id"><option value="">Lintas site</option>
    @foreach($sitesList as $st)<option value="{{ $st->id }}" @selected(old('site_id', $s?->site_id) == $st->id)>{{ $st->code }} — {{ $st->name }}</option>@endforeach</select></div>
<div class="field"><label for="sv-target">Target ketersediaan (%) <span class="muted">(kosong = target global {{ rtrim(rtrim(number_format((float) \App\Models\Setting::get('sla.target_pct', 99.5), 3, ',', ''), '0'), ',') }}%)</span></label>
    <input class="input @error('target_pct') is-err @enderror" id="sv-target" type="number" step="0.001" min="50" max="100" name="target_pct" value="{{ old('target_pct', $s?->target_pct) }}"><x-field-error name="target_pct"/></div>
