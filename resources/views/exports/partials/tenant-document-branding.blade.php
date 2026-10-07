<div style="margin-top: 18px; padding-top: 10px; border-top: 1px solid #cbd5e1; color: #475569; font-family: 'DejaVu Sans', sans-serif; font-size: 8px;">
    <table style="width: 100%; border-collapse: collapse;">
        <tr>
            @if ($branding['logo_data_uri'])
                <td style="width: 64px; padding: 0 10px 0 0; border: 0; vertical-align: top;">
                    <img src="{{ $branding['logo_data_uri'] }}" alt="" style="max-width: 56px; max-height: 38px;">
                </td>
            @endif
            <td style="padding: 0; border: 0; vertical-align: top;">
                <strong style="color: #0f172a;">{{ $branding['legal_name'] ?: $branding['name'] }}</strong>
                @if ($branding['legal_name'] && $branding['legal_name'] !== $branding['name'])
                    <span> · {{ $branding['name'] }}</span>
                @endif
                @php
                    $location = collect([$branding['address'], $branding['city'], $branding['country']])->filter()->implode(', ');
                    $contacts = collect([$branding['contact_phone'], $branding['contact_email'], $branding['website']])->filter()->implode(' · ');
                @endphp
                @if ($location)
                    <br>{{ $location }}
                @endif
                @if ($contacts)
                    <br>{{ $contacts }}
                @endif
                @if ($branding['document_footer'])
                    <div style="margin-top: 4px; white-space: pre-line;">{{ $branding['document_footer'] }}</div>
                @endif
            </td>
        </tr>
    </table>
</div>
