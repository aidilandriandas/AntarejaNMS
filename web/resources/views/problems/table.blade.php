@if($problems->isEmpty())
    <div class="empty"><x-icon name="check"/>{{ $empty ?? 'Tidak ada problem aktif.' }}</div>
@else
<div class="table-wrap"><table class="t">
    <thead><tr><th>Severity</th><th>Problem</th><th>Durasi</th><th>Status</th>@can('manage-devices')<th></th>@endcan</tr></thead>
    <tbody>@foreach($problems as $p)@include('problems.row', ['p' => $p, 'compact' => $compact ?? false])@endforeach</tbody>
</table></div>
@endif
