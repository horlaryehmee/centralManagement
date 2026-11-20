<div>
    <label for="f-{{ $name }}" class="mb-1.5 block text-sm font-medium text-zinc-800">{{ $label }}</label>
    <input id="f-{{ $name }}" name="{{ $name }}" type="{{ $type ?? 'text' }}" value="{{ $value ?? '' }}" placeholder="{{ $placeholder ?? '' }}" @if (($type ?? '') === 'password') autocomplete="new-password" @endif
        class="input @error($name) input-error @enderror">
    @error($name)
        <p class="mt-1.5 text-xs font-medium text-red-600">{{ $message }}</p>
    @elseif (! empty($help))
        <p class="mt-1.5 text-xs text-zinc-500">{{ $help }}</p>
    @enderror
</div>
