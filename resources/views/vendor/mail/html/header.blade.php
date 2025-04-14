@props(['url'])
<tr>
    <td class="header">
        <a href="{{ $url }}" style="display: inline-block;">
            @if (trim($slot) === 'Nkiapa’Nou')
                <img src="https://nkiapanou.com/assets/images/logo.jpeg" class="logo" alt="Logo">
            @else
                {{ $slot }}
            @endif
        </a>
    </td>
</tr>