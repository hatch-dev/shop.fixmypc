<?php

namespace Tests\Feature;

use App\Models\Admin;
use App\Models\Attribute;
use App\Models\AttributeValue;
use App\Models\InventoryAttribute;
use App\Models\UpdatedInventory;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Laravel\Passport\Passport;
use Tests\TestCase;

/**
 * Saving an attribute and its values.
 *
 * The cases here are the two things the save got wrong rather than a feature
 * being re-confirmed. It refused a delete with "being used by Order. Delete
 * the order first." when no order was involved — the check has always been
 * against product variants — and it named neither the value nor the product,
 * which the form cannot show either, because it hides a row as soon as it is
 * removed. And it wrote each title and colour as it walked the submitted
 * values, returning the refusal partway through, so a save the admin was told
 * had failed had already applied whatever the loop reached first.
 */
class AdminAttributeSaveTest extends TestCase
{
    use DatabaseTransactions;

    private function admin()
    {
        $admin = Admin::whereHas('roles', function ($query) {
            $query->where('name', 'superadmin');
        })->first();

        if (!$admin) {
            $this->markTestSkipped('No superadmin to drive the admin API with.');
        }

        $this->app['auth']->forgetGuards();

        Passport::actingAs($admin, ['admin'], 'admin');

        return $admin;
    }

    /** An attribute with the values named, returned as [title => model]. */
    private function makeAttribute(array $titles)
    {
        $admin = Admin::first();

        $attribute = Attribute::create([
            'title' => 'QA Attr ' . uniqid(),
            'admin_id' => $admin->id,
        ]);

        $values = [];

        foreach ($titles as $title) {
            $values[$title] = AttributeValue::create([
                'attribute_id' => $attribute->id,
                'title' => $title,
                'color' => '#112233',
                'admin_id' => $admin->id,
            ]);
        }

        return [$attribute, $values];
    }

    /** Attaches a value to a variant of a real product, as the catalogue does. */
    private function useOnAVariant(AttributeValue $value, $productId)
    {
        $inventory = UpdatedInventory::create([
            'product_id' => $productId,
            'quantity' => 1,
            'price' => 10,
            'admin_id' => Admin::first()->id,
        ]);

        InventoryAttribute::create([
            'inventory_id' => $inventory->id,
            'attribute_value_id' => $value->id,
        ]);

        return $inventory;
    }

    private function aProductId()
    {
        $id = DB::table('products')->value('id');

        if (!$id) {
            $this->markTestSkipped('No product to attach a variant to.');
        }

        return $id;
    }

    /** The save payload: every value, with the named ones blanked to delete. */
    private function payload(Attribute $attribute, array $values, array $deleteTitles = [], array $renames = [])
    {
        $rows = [];

        foreach ($values as $title => $value) {
            $rows[] = [
                'id' => $value->id,
                'title' => in_array($title, $deleteTitles, true) ? '' : ($renames[$title] ?? $title),
                'color' => $value->color,
            ];
        }

        return ['title' => $attribute->title, 'values' => $rows];
    }

    private function save($id, array $payload)
    {
        return $this->postJson('/api/admin/attribute/action/' . $id, $payload);
    }

    // ---- the refusal --------------------------------------------------------

    public function test_a_value_a_variant_uses_cannot_be_deleted()
    {
        $this->admin();

        [$attribute, $values] = $this->makeAttribute(['Grey', 'Teal']);
        $this->useOnAVariant($values['Grey'], $this->aProductId());

        $response = $this->save($attribute->id, $this->payload($attribute, $values, ['Grey']));

        $this->assertNotSame(200, (int)$response->json('status'));
        $this->assertDatabaseHas('attribute_values', ['id' => $values['Grey']->id]);
    }

    public function test_the_refusal_names_the_value_and_the_product_not_an_order()
    {
        $this->admin();

        $productId = $this->aProductId();
        $productTitle = DB::table('products')->where('id', $productId)->value('title');

        [$attribute, $values] = $this->makeAttribute(['Grey']);
        $this->useOnAVariant($values['Grey'], $productId);

        $message = $this->save($attribute->id, $this->payload($attribute, $values, ['Grey']))
            ->json('message');

        // What is actually in the way, and where to go and fix it.
        $this->assertStringContainsString('Grey', $message);
        $this->assertStringContainsString($productTitle, $message);

        /*
         * The old message sent the admin to delete an order. No order is
         * involved in this check and deleting one would not have helped.
         */
        $this->assertStringNotContainsStringIgnoringCase('order', $message,
            'The refusal should not blame an order: ' . $message);
    }

    public function test_the_refusal_names_every_blocking_value_not_just_the_first()
    {
        $this->admin();

        [$attribute, $values] = $this->makeAttribute(['Grey', 'Teal', 'Free']);

        $this->useOnAVariant($values['Grey'], $this->aProductId());
        $this->useOnAVariant($values['Teal'], $this->aProductId());

        $message = $this->save($attribute->id,
            $this->payload($attribute, $values, ['Grey', 'Teal', 'Free']))->json('message');

        /*
         * The form hides a row as soon as it is removed, so naming only the
         * first blocker leaves the admin with nothing to act on — they cannot
         * see what they asked to delete.
         */
        $this->assertStringContainsString('Grey', $message);
        $this->assertStringContainsString('Teal', $message);
    }

    public function test_a_blank_titled_value_is_described_rather_than_named()
    {
        $this->admin();

        [$attribute, $values] = $this->makeAttribute(['Grey']);

        AttributeValue::where('id', $values['Grey']->id)->update(['title' => '']);
        $this->useOnAVariant($values['Grey'], $this->aProductId());

        $message = $this->save($attribute->id, $this->payload($attribute, $values, ['Grey']))
            ->json('message');

        // '"" — used by 1 variant(s)' tells the admin nothing.
        $this->assertStringContainsString('the blank value', $message);
    }

    // ---- the refused save writes nothing ------------------------------------

    public function test_a_refused_save_leaves_every_other_value_untouched()
    {
        $this->admin();

        [$attribute, $values] = $this->makeAttribute(['Grey', 'Teal', 'Rose']);

        /*
         * The blocked value is deliberately LAST in the submitted order, and
         * the two renames come before it. That ordering is the whole test: the
         * loop this replaces wrote each value as it reached it, so by the time
         * it met the refusal it had already renamed Grey and Teal. Put the
         * blocker first instead and even the old code wrote nothing, which is
         * why this has to be pinned rather than left to the order values
         * happen to arrive in.
         */
        $this->useOnAVariant($values['Rose'], $this->aProductId());

        $payload = $this->payload($attribute, $values, ['Rose'],
            ['Grey' => 'Grey renamed', 'Teal' => 'Teal renamed']);

        $before = AttributeValue::where('attribute_id', $attribute->id)
            ->orderBy('id')->pluck('title', 'id')->toArray();

        $response = $this->save($attribute->id, $payload);

        $this->assertNotSame(200, (int)$response->json('status'));

        $after = AttributeValue::where('attribute_id', $attribute->id)
            ->orderBy('id')->pluck('title', 'id')->toArray();

        $this->assertSame($before, $after,
            'A refused save must write nothing at all, not the part it reached first.');
    }

    public function test_a_refused_save_does_not_rename_the_attribute_either()
    {
        $this->admin();

        [$attribute, $values] = $this->makeAttribute(['Grey']);
        $this->useOnAVariant($values['Grey'], $this->aProductId());

        $payload = $this->payload($attribute, $values, ['Grey']);
        $payload['title'] = 'Renamed by a save that failed';

        $this->save($attribute->id, $payload);

        $this->assertSame($attribute->title,
            Attribute::find($attribute->id)->title);
    }

    // ---- what still has to work ---------------------------------------------

    public function test_an_unused_value_is_still_deleted()
    {
        $this->admin();

        [$attribute, $values] = $this->makeAttribute(['Grey', 'Teal']);

        $response = $this->save($attribute->id, $this->payload($attribute, $values, ['Teal']));

        $this->assertSame(200, (int)$response->json('status'), json_encode($response->json()));
        $this->assertDatabaseMissing('attribute_values', ['id' => $values['Teal']->id]);
        $this->assertDatabaseHas('attribute_values', ['id' => $values['Grey']->id]);
    }

    public function test_renaming_recolouring_and_adding_still_work_in_one_save()
    {
        $this->admin();

        [$attribute, $values] = $this->makeAttribute(['Grey', 'Teal']);

        $payload = $this->payload($attribute, $values, [], ['Grey' => 'Graphite']);
        $payload['values'][0]['color'] = '#abcdef';
        $payload['values'][] = ['title' => 'Sand', 'color' => '#ffeecc'];
        $payload['title'] = 'Finish';

        $response = $this->save($attribute->id, $payload);

        $this->assertSame(200, (int)$response->json('status'), json_encode($response->json()));

        $this->assertDatabaseHas('attribute_values',
            ['id' => $values['Grey']->id, 'title' => 'Graphite', 'color' => '#abcdef']);

        $this->assertDatabaseHas('attribute_values',
            ['attribute_id' => $attribute->id, 'title' => 'Sand']);

        $this->assertSame('Finish', Attribute::find($attribute->id)->title);
    }

    public function test_a_new_row_left_blank_does_not_create_a_nameless_value()
    {
        $this->admin();

        [$attribute, $values] = $this->makeAttribute(['Grey']);

        $payload = $this->payload($attribute, $values);
        $payload['values'][] = ['title' => '', 'color' => ''];

        $this->save($attribute->id, $payload);

        $this->assertSame(1, AttributeValue::where('attribute_id', $attribute->id)->count());
    }
}
