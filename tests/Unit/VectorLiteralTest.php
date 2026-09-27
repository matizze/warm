<?php

namespace Tests\Unit;

use App\Support\VectorLiteral;
use Tests\TestCase;

class VectorLiteralTest extends TestCase
{
    public function test_formats_floats_as_a_pgvector_literal(): void
    {
        $this->assertSame('[0.1,0.2,0.3]', VectorLiteral::fromArray([0.1, 0.2, 0.3]));
    }

    public function test_formats_integers(): void
    {
        $this->assertSame('[1,2]', VectorLiteral::fromArray([1, 2]));
    }

    public function test_formats_an_empty_vector(): void
    {
        $this->assertSame('[]', VectorLiteral::fromArray([]));
    }
}
