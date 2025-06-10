@use('Illuminate\Support\HtmlString')
@include('wayfinder::docblock')
{!! when(($export ?? true) && !$isInvokable, 'export ') !!}const {!! $method !!} = (@include('wayfinder::function-arguments')) => ({
    url: {!! $method !!}.url({!! when($parameters->isNotEmpty(), 'args, ') !!}options),
    method: @js($verbs->first()->actual),
})

{!! $method !!}.definition = {
    methods: [@foreach ($verbs as $verb)@js($verb->actual){!! when(! $loop->last, ',') !!}@endforeach],
    url: @js($uri),
}

@include('wayfinder::docblock')
{!! $method !!}.url = (@include('wayfinder::function-arguments')) => {
@if ($parameters->count() === 1)
    if (typeof args === 'string' || typeof args === 'number') {
        args = { {!! $parameters->first()->name !!}: args }
    }

@if ($parameters->first()->key)
    if (typeof args === 'object' && !Array.isArray(args) && @js($parameters->first()->key) in args) {
        args = { {!! $parameters->first()->name !!}: args.{!! $parameters->first()->key !!} }
    }

@endif
@endif
@if ($parameters->isNotEmpty())
    if (Array.isArray(args)) {
        args = {
@foreach ($parameters as $parameter)
            {!! $parameter->name !!}: args[{!! $loop->index !!}],
@endforeach
        }
    }

@endif
@if ($parameters->where('optional')->isNotEmpty())
    validateParameters(args, [
@foreach ($parameters->where('optional') as $parameter)
        "{!! $parameter->name !!}",
@endforeach
    ])

@endif
@if ($parameters->isNotEmpty())
    const parsedArgs = {
@foreach ($parameters as $parameter)
@if ($parameter->key)
        {!! $parameter->name !!}: {!! when($parameter->default !== null, '(') !!}typeof args{!! when($parameters->every->optional, '?') !!}.{!! $parameter->name !!} === 'object'
            ? args.{!! $parameter->name !!}.{!! $parameter->key ?? 'id' !!}
            : args{!! when($parameters->every->optional, '?') !!}.{!! $parameter->name !!}{!! when($parameter->default !== null, ') ?? ') !!}@if ($parameter->default !== null)@js($parameter->default)@endif,
@else
        {!! $parameter->name !!}: args{!! when($parameters->every->optional, '?') !!}.{!! $parameter->name !!}{!! when($parameter->default !== null, ' ?? ') !!}@if ($parameter->default !== null)@js($parameter->default)@endif,
@endif
@endforeach
    }

@endif
    return {!! $method !!}.definition.url
@foreach ($parameters as $parameter)
            .replace(@js($parameter->placeholder), parsedArgs.{!! $parameter->name !!}{!! when($parameter->optional, '?') !!}.toString(){!! when($parameter->optional, " ?? ''") !!})
@if ($loop->last)
            .replace(/\/+$/, '')
@endif
@endforeach + queryParams(options)
}

@foreach ($verbs as $verb)
@include('wayfinder::docblock')
{!! $method !!}.{!! $verb->actual !!} = (@include('wayfinder::function-arguments')) => ({
    url: {!! $method !!}.url({!! when($parameters->isNotEmpty(), 'args, ') !!}options),
    method: @js($verb->actual),
})

@endforeach
@if ($withForm)
@include('wayfinder::docblock')
const {!! $method !!}Form = (@include('wayfinder::function-arguments')) => ({
    action: {!! $method !!}.url({!! when($parameters->isNotEmpty(), 'args, ') !!}@if ($verbs->first()->formSafe !== $verbs->first()->actual)
{
    [options?.mergeQuery ? 'mergeQuery' : 'query']: {
        _method: @js(strtoupper($verbs->first()->actual)),
        ...(options?.query ?? options?.mergeQuery ?? {}),
    }
}
@else
options
@endif),
    method: @js($verbs->first()->formSafe),
})

@foreach ($verbs as $verb)
@include('wayfinder::docblock')
{!! $method !!}Form.{!! $verb->actual !!} = (@include('wayfinder::function-arguments')) => ({
    action: {!! $method !!}.url({!! when($parameters->isNotEmpty(), 'args, ') !!}@if ($verb->formSafe !== $verb->actual)
{
    [options?.mergeQuery ? 'mergeQuery' : 'query']: {
        _method: @js(strtoupper($verb->actual)),
        ...(options?.query ?? options?.mergeQuery ?? {}),
    }
}
@else
options
@endif),
    method: @js($verb->formSafe),
})

@endforeach
{!! $method !!}.form = {!! $method !!}Form

@endif
