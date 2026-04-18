<?php

namespace {
    use Tests\TestCase;

    \DG\BypassFinals::enable();

    pest()->extend(TestCase::class)->in('Unit', 'Feature');

    afterEach(fn() => \Mockery::close());
}
