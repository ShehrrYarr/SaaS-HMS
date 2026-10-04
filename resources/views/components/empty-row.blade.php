@props(['colspan' => 1, 'message' => 'No records found.', 'icon' => 'ri-inbox-line'])
<tr>
    <td colspan="{{ $colspan }}" class="text-center text-muted py-5">
        <i class="{{ $icon }} fs-1 d-block mb-2 opacity-50"></i>{{ $message }}
    </td>
</tr>
