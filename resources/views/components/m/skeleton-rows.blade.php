@props(['rows' => 3])
<div class="m-group" aria-busy="true">
    @for ($i = 0; $i < $rows; $i++)
        <div class="m-row">
            <span class="m-skel" style="width:40px;height:40px;border-radius:12px"></span>
            <span class="m-row__body">
                <span class="m-skel" style="display:block;width:{{ 55 + (($i * 17) % 30) }}%;height:14px"></span>
                <span class="m-skel" style="display:block;width:{{ 35 + (($i * 11) % 25) }}%;height:11px;margin-top:7px"></span>
            </span>
            <span class="m-skel" style="width:64px;height:14px"></span>
        </div>
    @endfor
</div>
