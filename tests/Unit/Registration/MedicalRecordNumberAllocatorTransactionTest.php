<?php

namespace Tests\Unit\Registration;

use App\Support\Registration\MedicalRecordNumberAllocator;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Support\Facades\DB;
use LogicException;
use Mockery;
use Tests\TestCase;

class MedicalRecordNumberAllocatorTransactionTest extends TestCase
{
    public function test_allocator_refuses_to_run_outside_an_active_transaction(): void
    {
        $connection = Mockery::mock(ConnectionInterface::class);
        $connection->shouldReceive('transactionLevel')->once()->andReturn(0);
        DB::shouldReceive('connection')->once()->andReturn($connection);

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('active database transaction');

        (new MedicalRecordNumberAllocator)->allocate();
    }
}
