<?php

namespace Database\Seeders;

use App\Models\PaymentMethod;
use Illuminate\Database\Seeder;

class PaymentMethodSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $paymentMethods = [
            [
                'code' => 'cash',
                'name' => 'Espèces',
                'icon' => '💵',
                'description' => 'Paiement en espèces',
                'is_active' => true,
                'sort_order' => 1,
            ],
            [
                'code' => 'mobile_money',
                'name' => 'Mobile Money',
                'icon' => '📱',
                'description' => 'Orange Money, MTN Mobile Money, Wave, etc.',
                'is_active' => true,
                'sort_order' => 2,
            ],
            [
                'code' => 'bank_transfer',
                'name' => 'Virement bancaire',
                'icon' => '🏦',
                'description' => 'Virement depuis un compte bancaire',
                'is_active' => true,
                'sort_order' => 3,
            ],
            [
                'code' => 'cheque',
                'name' => 'Chèque',
                'icon' => '📝',
                'description' => 'Paiement par chèque',
                'is_active' => true,
                'sort_order' => 4,
            ],
        ];

        foreach ($paymentMethods as $method) {
            PaymentMethod::updateOrCreate(
                ['code' => $method['code']],
                $method
            );
        }

        $this->command->info('✅ Modes de paiement créés avec succès!');
    }
}
