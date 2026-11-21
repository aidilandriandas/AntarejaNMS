@if(session('ok'))<div class="alert ok" role="status"><x-icon name="check"/><div>{{ session('ok') }}</div></div>@endif
@if(session('error'))<div class="alert error" role="alert"><x-icon name="alert"/><div>{{ session('error') }}</div></div>@endif
@if($errors->any() && ! ($hideErrorSummary ?? false))<div class="alert error" role="alert"><x-icon name="alert"/><div>Periksa kembali isian yang ditandai.</div></div>@endif
