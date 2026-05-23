@extends('welcome')

@section('content')
    <section class="page-head">
        <div>
            <h1>{{ $heading }}</h1>
            <p class="lead">{{ $description }}</p>
        </div>
    </section>

    <section class="grid two">
        @foreach ($items as $item)
            <div class="panel">
                <h2>{{ $item['title'] }}</h2>
                <p class="lead">{{ $item['body'] }}</p>
            </div>
        @endforeach
    </section>
@endsection

