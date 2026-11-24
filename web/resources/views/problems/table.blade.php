@if($problems->isEmpty())
    <div class="empty"><x-icon name="check"/>{{ $empty ?? 'Tidak ada problem aktif.' }}</div>
@else
<div class="table-wrap"><table class="t">
    <thead><tr>@if($select ?? false)<th class="chk"><span class="sr-only">Pilih</span></th>@endif<th>Severity</th><th>Problem</th><th>Durasi</th><th>Status</th>@can('ack-problems')<th></th>@endcan</tr></thead>
    <tbody>@foreach($problems as $p)@include('problems.row', ['p' => $p, 'compact' => $compact ?? false, 'select' => $select ?? false, 'impactLines' => $impactLines ?? [], 'runbookFor' => $runbookFor ?? []])@endforeach</tbody>
</table></div>
@endif
