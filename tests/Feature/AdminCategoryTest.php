<?php

namespace Tests\Feature;

use App\Console\Commands\PublishDueCategories;
use App\Models\Admin;
use App\Models\Category;
use App\Models\Helper\CategoryTree;
use App\Models\ProductCategory;
use App\Models\Product;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Laravel\Passport\Passport;
use Tests\TestCase;

/**
 * The admin Category module, end to end through the real routes.
 *
 * Almost everything here is a case the module used to get wrong rather than a
 * feature being re-confirmed: the list could only ever return root categories,
 * the re-parent endpoint had no permission check and no cycle check, deleting
 * a parent orphaned its children onto `parent = 0` where nothing could find
 * them again, and the update path wrote whatever columns the request named
 * while silently dropping every value that happened to be falsy.
 */
class AdminCategoryTest extends TestCase
{
    use DatabaseTransactions;

    // ---- fixtures -----------------------------------------------------------

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

    private function asGuest()
    {
        $this->app['auth']->forgetGuards();
    }

    /** A category created straight through the model, for arranging a case. */
    private function makeCategory($attributes = [])
    {
        $admin = Admin::first();

        $suffix = uniqid();

        return Category::create(array_merge([
            'id' => \App\Models\Helper\Utils::idGenerator(new Category()),
            'admin_id' => $admin->id,
            'title' => 'QA Cat ' . $suffix,
            'slug' => 'qa-cat-' . $suffix,
            'image' => 'default-image.webp',
            'status' => 1,
            'display_order' => 1,
        ], $attributes))->fresh();
    }

    /** The payload the admin form posts, with everything required filled in. */
    private function payload($overrides = [])
    {
        $suffix = uniqid();

        return array_merge([
            'title' => 'Test Category ' . $suffix,
            'slug' => 'test-category-' . $suffix,
            'meta_title' => 'Test Category',
            'meta_description' => 'A category created by the test suite.',
            'status' => 1,
            'display_order' => 1,
            'products_per_page' => 12,
        ], $overrides);
    }

    private function create($overrides = [])
    {
        return $this->postJson('/api/admin/category/action', $this->payload($overrides));
    }

    private function listing($params = [])
    {
        return $this->getJson('/api/admin/category/all?' . http_build_query($params));
    }

    /** A 1x1 PNG as the form's file reader would produce it. */
    private function pngDataUrl()
    {
        return 'data:image/png;base64,'
            . 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==';
    }

    // ---- 1-2. creating root and child categories ---------------------------

    public function test_a_root_category_is_created_and_is_listed()
    {
        $this->admin();

        $response = $this->create(['title' => 'QA Root', 'slug' => 'qa-root-' . uniqid()]);

        $this->assertSame(200, (int)$response->json('status'), json_encode($response->json()));

        $id = $response->json('data.id');

        $this->assertNotEmpty($id);
        $this->assertNull($response->json('data.parent'), 'A category with no parent should be a root.');

        // Immediately visible in the list, which is what "reflected in the
        // admin panel" has to mean.
        $found = collect($this->listing(['q' => 'QA Root'])->json('data.data'))
            ->firstWhere('id', $id);

        $this->assertNotNull($found, 'The new category is not in the list.');
        $this->assertSame(0, (int)$found['children_count']);
        $this->assertSame(0, (int)$found['products_count']);
    }

    public function test_a_child_category_is_created_under_its_parent()
    {
        $this->admin();

        $parent = $this->makeCategory(['title' => 'QA Parent']);

        $response = $this->create(['title' => 'QA Child', 'parent' => $parent->id]);

        $this->assertSame(200, (int)$response->json('status'), json_encode($response->json()));
        $this->assertSame((int)$parent->id, (int)$response->json('data.parent'));

        // The relationship is what the database holds, not only what the
        // response said.
        $this->assertSame(1, Category::where('parent', $parent->id)->count());
    }

    // ---- 3-4. image and SEO ------------------------------------------------

    public function test_a_category_is_created_with_an_uploaded_icon()
    {
        $this->admin();

        $response = $this->create(['icon' => $this->pngDataUrl()]);

        $this->assertSame(200, (int)$response->json('status'), json_encode($response->json()));

        $image = $response->json('data.image');

        $this->assertStringEndsWith('.png', (string)$image);
        $this->assertNotSame('default-image.webp', $image);

        $path = base_path('uploads/' . $image);

        $this->assertFileExists($path, 'The decoded image was not written.');
        $this->assertNotEmpty($response->json('data.image_url'), 'No usable URL came back for the image.');

        @unlink($path);
    }

    public function test_seo_fields_are_stored_and_read_back()
    {
        $this->admin();

        $response = $this->create([
            'meta_title' => 'QA SEO Title',
            'meta_description' => 'QA SEO description for the category.',
            'meta_keywords' => 'qa,seo,category',
            'canonical_url' => 'https://example.test/category/qa',
        ]);

        $this->assertSame(200, (int)$response->json('status'), json_encode($response->json()));

        $found = $this->getJson('/api/admin/category/find/' . $response->json('data.id'))->json('data');

        $this->assertSame('QA SEO Title', $found['meta_title']);
        $this->assertSame('qa,seo,category', $found['meta_keywords']);
        $this->assertSame('https://example.test/category/qa', $found['canonical_url']);
    }

    // ---- 5-7. invalid and duplicate input -----------------------------------

    public function test_an_empty_name_is_refused()
    {
        $this->admin();

        $response = $this->postJson('/api/admin/category/action',
            $this->payload(['title' => '']));

        $this->assertSame(201, (int)$response->json('status'));
        $this->assertArrayHasKey('title', (array)$response->json('data.fields'));
    }

    public function test_a_malformed_slug_is_refused_with_a_useful_message()
    {
        $this->admin();

        // Str::slug would tidy this, so it is posted as a field the form
        // control owns rather than left to be derived.
        $response = $this->postJson('/api/admin/category/action',
            $this->payload(['slug' => 'Not A Slug!!']));

        // Slugified to "not-a-slug" and accepted, rather than stored raw and
        // 404-ing on the storefront.
        $this->assertSame(200, (int)$response->json('status'), json_encode($response->json()));
        $this->assertSame('not-a-slug', $response->json('data.slug'));
    }

    public function test_a_duplicate_slug_is_refused()
    {
        $this->admin();

        $existing = $this->makeCategory();

        $response = $this->postJson('/api/admin/category/action',
            $this->payload(['slug' => $existing->slug]));

        $this->assertSame(201, (int)$response->json('status'));
        $this->assertStringContainsStringIgnoringCase('slug',
            json_encode($response->json('data')));

        // And nothing was written.
        $this->assertSame(1, Category::where('slug', $existing->slug)->count());
    }

    /**
     * A refusal that belongs to one field has to say which field.
     *
     * The slug and parent checks answered with {"slug": ["..."]}, and no form
     * reads that key - the pages read `form` and `image` - so the message
     * reached the browser and was shown nowhere. The save simply looked as
     * though it had done nothing at all.
     */
    public function test_a_refusal_about_one_field_names_that_field()
    {
        $this->admin();

        $existing = $this->makeCategory();

        $response = $this->postJson('/api/admin/category/action',
            $this->payload(['slug' => $existing->slug]));

        $this->assertSame(201, (int)$response->json('status'));

        // Named, for the message under the control...
        $this->assertArrayHasKey('slug', (array)$response->json('data.fields'));

        // ...and still in the flat list every other form renders.
        $this->assertNotEmpty($response->json('data.form'));
    }

    /**
     * in_footer is a 1/2 column, and the form used to send true/false.
     *
     * Every save from the category form came back with two complaints about
     * the footer toggle - "the in footer must be an integer" and "the selected
     * in footer is invalid" - whether or not the admin had touched it, and
     * there was nothing they could do on the form to get rid of them.
     */
    public function test_the_footer_toggle_takes_the_values_the_form_now_sends()
    {
        $this->admin();

        foreach ([1, 2] as $value) {
            $response = $this->create(['in_footer' => $value]);

            $this->assertSame(200, (int)$response->json('status'), json_encode($response->json()));
            $this->assertSame($value, (int)$response->json('data.in_footer'));
        }

        // What the form used to post, and what it was refused for.
        $response = $this->create(['in_footer' => false]);

        $this->assertSame(201, (int)$response->json('status'));
        $this->assertArrayHasKey('in_footer', (array)$response->json('data.fields'));
    }

    /**
     * The category form has no control for storefront visibility or
     * searchability any more, so it sends neither.
     *
     * Two things have to hold for that to be safe, and they are the reason
     * the keys were taken out of the form's payload rather than just hidden:
     * a new category has to come out visible and searchable (the column
     * defaults), and an edit must not quietly reset what is already stored -
     * writableAttributes() only writes what the request carries.
     */
    public function test_visibility_and_searchability_survive_a_form_that_does_not_send_them()
    {
        $this->admin();

        $created = $this->create();

        $this->assertSame(200, (int)$created->json('status'), json_encode($created->json()));

        $category = Category::find($created->json('data.id'));

        $this->assertSame(1, (int)$category->in_frontend);
        $this->assertSame(1, (int)$category->searchable);

        // Now a record deliberately hidden from the storefront, edited from a
        // form that has nothing to say about either column.
        $hidden = $this->makeCategory(['in_frontend' => 2, 'searchable' => 0]);

        $response = $this->postJson('/api/admin/category/action/' . $hidden->id,
            $this->payload(['title' => 'Renamed', 'slug' => 'renamed-' . uniqid()]));

        $this->assertSame(200, (int)$response->json('status'), json_encode($response->json()));

        $hidden->refresh();

        $this->assertSame('Renamed', $hidden->title);
        $this->assertSame(2, (int)$hidden->in_frontend);
        $this->assertSame(0, (int)$hidden->searchable);
    }

    // ---- scheduled publishing ----------------------------------------------

    /**
     * "Schedule Publishing" did nothing whatsoever.
     *
     * The form collected a date, the column stored it, and not one line in
     * the application ever read it back - no command, no query, no storefront
     * filter. A category scheduled for Monday was still a draft on Tuesday,
     * and on the Tuesday after that.
     */
    public function test_a_category_is_published_when_its_scheduled_time_arrives()
    {
        $this->admin();

        $category = $this->makeCategory([
            'status' => 2,
            'publish_at' => now()->addDays(3),
        ]);

        /*
         * Asserted on this category, never on the sweep's return count. The
         * count is over the whole table, so any other row that happens to be
         * due - left by a concurrent run, or simply real data on the machine
         * the suite is run on - made these tests fail for a reason that had
         * nothing to do with what they are checking.
         */

        // Not yet. A schedule in the future is not an instruction for today.
        PublishDueCategories::sweep();
        $this->assertSame(2, (int)$category->fresh()->status);

        DB::table('categories')->where('id', $category->id)
            ->update(['publish_at' => now()->subMinute()]);

        PublishDueCategories::sweep();

        $category->refresh();

        $this->assertSame(1, (int)$category->status);

        // Cleared as it publishes, which is what makes the sweep safe to run
        // for ever - see the next test.
        $this->assertNull($category->publish_at);
    }

    /**
     * The sweep publishes and never unpublishes, and it never acts twice on
     * the same instruction.
     *
     * An admin who takes a category back down a fortnight later would
     * otherwise find it public again within five minutes, carried out by an
     * instruction that had already been carried out once.
     */
    public function test_a_schedule_is_not_carried_out_twice()
    {
        $this->admin();

        $category = $this->makeCategory([
            'status' => 2,
            'publish_at' => now()->subMinute(),
        ]);

        PublishDueCategories::sweep();
        $this->assertSame(1, (int)$category->fresh()->status);

        // The admin changes their mind.
        DB::table('categories')->where('id', $category->id)->update(['status' => 2]);

        PublishDueCategories::sweep();
        $this->assertSame(2, (int)$category->fresh()->status);
    }

    /** Publishing by hand overtakes a pending schedule rather than leaving it armed. */
    public function test_publishing_directly_cancels_a_pending_schedule()
    {
        $this->admin();

        $response = $this->create([
            'status' => 1,
            'publish_at' => now()->addDays(3)->utc()->toIso8601ZuluString('millisecond'),
        ]);

        $this->assertSame(200, (int)$response->json('status'), json_encode($response->json()));

        $category = Category::find($response->json('data.id'));

        $this->assertSame(1, (int)$category->status);
        $this->assertNull($category->publish_at);
    }

    /** The list's publish toggle is the other door into the same state. */
    public function test_publishing_from_the_list_cancels_a_pending_schedule()
    {
        $this->admin();

        $category = $this->makeCategory([
            'status' => 2,
            'publish_at' => now()->addDays(3),
        ]);

        $response = $this->postJson('/api/admin/category/update-status', [
            'id' => $category->id,
            'status' => 1,
        ]);

        $this->assertSame(200, (int)$response->json('status'), json_encode($response->json()));

        $category->refresh();

        $this->assertSame(1, (int)$category->status);
        $this->assertNull($category->publish_at);

        // So taking it down again is not undone five minutes later.
        DB::table('categories')->where('id', $category->id)->update(['status' => 2]);

        PublishDueCategories::sweep();
        $this->assertSame(2, (int)$category->fresh()->status);
    }

    /**
     * The schedule is an instant, not a wall clock.
     *
     * A <input type="datetime-local"> has no timezone, so the form sends the
     * admin's chosen time as a UTC instant and the column keeps it in the
     * application's timezone. Nine in the morning in Dublin during BST has to
     * land on 08:00, or the category goes live an hour late all summer.
     */
    public function test_the_scheduled_time_is_stored_as_the_instant_that_was_chosen()
    {
        $this->admin();

        // 2026-07-24 09:00 in Dublin (BST, UTC+1). Saved as a draft, or the
        // schedule is cancelled on the way in - payload() publishes.
        $response = $this->create([
            'status' => 2,
            'publish_at' => '2026-07-24T08:00:00.000Z',
        ]);

        $this->assertSame(200, (int)$response->json('status'), json_encode($response->json()));

        $stored = DB::table('categories')->where('id', $response->json('data.id'))->value('publish_at');

        $this->assertSame('2026-07-24 08:00:00', (string)$stored);
    }

    /**
     * The list runs the sweep, so a schedule comes due on a host where cron
     * is not ticking - the arrangement flash-sale:expire already uses, and
     * this deploys to cPanel, where nothing keeps a worker alive.
     */
    public function test_opening_the_list_brings_a_due_category_live()
    {
        $this->admin();

        $category = $this->makeCategory([
            'status' => 2,
            'publish_at' => now()->subMinute(),
        ]);

        $this->listing();

        $this->assertSame(1, (int)$category->fresh()->status);
    }

    /**
     * Publication status has two states everywhere else in the application -
     * the list, the bulk action and updateStatus all read 1 and 2 - and the
     * form offered a third "Draft" radio posting 3, which could never save.
     */
    public function test_a_status_the_column_does_not_have_is_refused_by_name()
    {
        $this->admin();

        $response = $this->create(['status' => 3]);

        $this->assertSame(201, (int)$response->json('status'));
        $this->assertArrayHasKey('status', (array)$response->json('data.fields'));
    }

    public function test_the_slug_endpoint_reports_availability_and_suggests_a_free_one()
    {
        $this->admin();

        $existing = $this->makeCategory();

        $taken = $this->getJson('/api/admin/category/slug-available?slug=' . $existing->slug);

        $this->assertFalse($taken->json('data.available'));
        $this->assertNotSame($existing->slug, $taken->json('data.suggestion'));

        // Editing the category that already holds it does not collide with itself.
        $self = $this->getJson('/api/admin/category/slug-available?slug='
            . $existing->slug . '&id=' . $existing->id);

        $this->assertTrue($self->json('data.available'));

        $free = $this->getJson('/api/admin/category/slug-available?slug=free-slug-' . uniqid());

        $this->assertTrue($free->json('data.available'));
    }

    public function test_a_slug_is_derived_from_the_title_when_the_form_sends_none()
    {
        $this->admin();

        $payload = $this->payload(['title' => 'Derived Slug ' . uniqid()]);
        unset($payload['slug']);

        $response = $this->postJson('/api/admin/category/action', $payload);

        $this->assertSame(200, (int)$response->json('status'), json_encode($response->json()));
        $this->assertSame(\Illuminate\Support\Str::slug($payload['title']), $response->json('data.slug'));
    }

    // ---- 8-13. editing ------------------------------------------------------

    public function test_editing_a_category_updates_only_what_was_sent()
    {
        $this->admin();

        $category = $this->makeCategory(['description' => 'Original body']);

        $response = $this->postJson('/api/admin/category/action/' . $category->id,
            $this->payload([
                'title' => 'Renamed',
                'slug' => $category->slug,
                'description' => 'Rewritten body',
            ]));

        $this->assertSame(200, (int)$response->json('status'), json_encode($response->json()));

        $fresh = $category->fresh();

        $this->assertSame('Renamed', $fresh->title);
        $this->assertSame('Rewritten body', $fresh->description);
        $this->assertSame((int)$category->admin_id, (int)$fresh->admin_id, 'Ownership moved during an edit.');
    }

    /**
     * The update path used to run its payload through array_filter(), which
     * drops everything falsy — so a description could be written but never
     * cleared, and every toggle could be switched on and never off.
     */
    public function test_a_field_can_be_cleared_and_a_toggle_can_be_switched_off()
    {
        $this->admin();

        $category = $this->makeCategory([
            'description' => 'Something',
            'show_in_nav' => 1,
            'searchable' => 1,
            'show_homepage' => 1,
        ]);

        $response = $this->postJson('/api/admin/category/action/' . $category->id,
            $this->payload([
                'slug' => $category->slug,
                'description' => '',
                'show_in_nav' => false,
                'searchable' => false,
                'show_homepage' => false,
            ]));

        $this->assertSame(200, (int)$response->json('status'), json_encode($response->json()));

        $fresh = $category->fresh();

        $this->assertNull($fresh->description, 'A cleared description was not cleared.');
        $this->assertSame(0, (int)$fresh->show_in_nav, 'show_in_nav could not be switched off.');
        $this->assertSame(0, (int)$fresh->searchable, 'searchable could not be switched off.');
        $this->assertSame(0, (int)$fresh->show_homepage, 'show_homepage could not be switched off.');
    }

    /**
     * `featured` and `in_frontend` are on the form but were missing from the
     * model's $fillable, so both were accepted, reported as saved, and
     * discarded.
     */
    public function test_the_visibility_toggles_actually_persist()
    {
        $this->admin();

        $category = $this->makeCategory(['featured' => 2, 'in_frontend' => 2, 'in_footer' => 2]);

        $this->postJson('/api/admin/category/action/' . $category->id,
            $this->payload([
                'slug' => $category->slug,
                'featured' => 1,
                'in_frontend' => 1,
                'in_footer' => 1,
            ]))->assertOk();

        $fresh = $category->fresh();

        $this->assertSame(1, (int)$fresh->featured, 'featured did not save.');
        $this->assertSame(1, (int)$fresh->in_frontend, 'in_frontend did not save.');
        $this->assertSame(1, (int)$fresh->in_footer, 'in_footer did not save.');
    }

    public function test_the_parent_can_be_changed_and_cleared()
    {
        $this->admin();

        $first = $this->makeCategory();
        $second = $this->makeCategory();
        $child = $this->makeCategory(['parent' => $first->id]);

        $moved = $this->postJson('/api/admin/category/update-parent', [
            'id' => $child->id, 'parent_id' => $second->id,
        ]);

        $this->assertSame(200, (int)$moved->json('status'), json_encode($moved->json()));
        $this->assertSame((int)$second->id, (int)$child->fresh()->parent);

        $promoted = $this->postJson('/api/admin/category/update-parent', [
            'id' => $child->id, 'parent_id' => null,
        ]);

        $this->assertSame(200, (int)$promoted->json('status'));
        $this->assertNull($child->fresh()->parent, 'Clearing the parent did not make it a root.');
    }

    public function test_re_parenting_does_not_transfer_ownership()
    {
        $admin = $this->admin();

        $owner = Admin::where('id', '!=', $admin->id)->first() ?: $admin;

        $parent = $this->makeCategory();
        $child = $this->makeCategory(['admin_id' => $owner->id]);

        $this->postJson('/api/admin/category/update-parent', [
            'id' => $child->id, 'parent_id' => $parent->id,
        ])->assertOk();

        $this->assertSame((int)$owner->id, (int)$child->fresh()->admin_id,
            'Re-parenting reassigned admin_id to the caller.');
    }

    public function test_an_image_is_replaced_and_can_be_removed()
    {
        $this->admin();

        $created = $this->create(['icon' => $this->pngDataUrl()]);
        $id = $created->json('data.id');
        $first = $created->json('data.image');

        // Replace.
        $replaced = $this->postJson('/api/admin/category/action/' . $id,
            $this->payload([
                'slug' => $created->json('data.slug'),
                'icon' => $this->pngDataUrl(),
            ]));

        $second = $replaced->json('data.image');

        $this->assertNotSame($first, $second, 'Uploading a new icon did not replace the old one.');
        $this->assertFileDoesNotExist(base_path('uploads/' . $first), 'The replaced file was left behind.');

        // Remove.
        $removed = $this->postJson('/api/admin/category/action/' . $id,
            $this->payload([
                'slug' => $created->json('data.slug'),
                'icon' => null,
            ]));

        $this->assertSame(config('constants.media.DEFAULT_IMAGE'), $removed->json('data.image'),
            'Removing the image did not fall back to the default.');

        @unlink(base_path('uploads/' . $second));
    }

    public function test_an_invalid_or_oversized_image_is_refused()
    {
        $this->admin();

        $badType = $this->create(['icon' => 'data:image/php;base64,PD9waHAgZWNobyAxOw==']);

        $this->assertSame(201, (int)$badType->json('status'),
            'An executable disguised as an image was accepted.');

        // Comfortably past the 2MB cap once decoded.
        $huge = 'data:image/png;base64,' . str_repeat('A', 3 * 1024 * 1024);

        $oversized = $this->create(['icon' => $huge]);

        $this->assertSame(201, (int)$oversized->json('status'), 'An oversized image was accepted.');
    }

    public function test_the_status_endpoint_publishes_and_unpublishes()
    {
        $this->admin();

        $category = $this->makeCategory(['status' => 2]);

        $response = $this->postJson('/api/admin/category/update-status', [
            'id' => $category->id, 'status' => 1,
        ]);

        $this->assertSame(200, (int)$response->json('status'), json_encode($response->json()));
        $this->assertSame(1, (int)$category->fresh()->status);

        // Bulk, and cascading down a branch.
        $child = $this->makeCategory(['parent' => $category->id, 'status' => 1]);

        $this->postJson('/api/admin/category/update-status', [
            'id' => $category->id, 'status' => 2, 'cascade' => true,
        ])->assertOk();

        $this->assertSame(2, (int)$category->fresh()->status);
        $this->assertSame(2, (int)$child->fresh()->status, 'Cascade left the child published under a hidden parent.');
    }

    public function test_an_invalid_status_is_refused()
    {
        $this->admin();

        $category = $this->makeCategory(['status' => 1]);

        $response = $this->postJson('/api/admin/category/update-status', [
            'id' => $category->id, 'status' => 99,
        ]);

        $this->assertNotSame(200, (int)$response->json('status'));
        $this->assertSame(1, (int)$category->fresh()->status, 'A refused status change was applied anyway.');
    }

    public function test_sort_order_is_stored_and_sortable()
    {
        $this->admin();

        $low = $this->makeCategory(['display_order' => 2]);
        $high = $this->makeCategory(['display_order' => 900]);

        $rows = collect($this->listing([
            'orderby' => 'display_order', 'type' => 'desc', 'limit' => 100,
        ])->json('data.data'))->pluck('id')->map(function ($v) { return (string)$v; });

        $this->assertTrue(
            $rows->search((string)$high->id) < $rows->search((string)$low->id),
            'display_order sorting did not put the higher value first.'
        );

        // And an invalid one is refused rather than written.
        $refused = $this->postJson('/api/admin/category/action/' . $low->id,
            $this->payload(['slug' => $low->slug, 'display_order' => 'abc']));

        $this->assertSame(201, (int)$refused->json('status'));
        $this->assertSame(2, (int)$low->fresh()->display_order);
    }

    // ---- 14-18. search, filter, sort, pagination ---------------------------

    public function test_search_matches_name_slug_and_parent_name()
    {
        $this->admin();

        $token = strtolower('zz' . substr(uniqid(), -6));

        $parent = $this->makeCategory(['title' => 'Parent ' . $token, 'slug' => 'parent-' . $token]);
        $child = $this->makeCategory(['title' => 'Child One', 'slug' => 'child-' . $token, 'parent' => $parent->id]);

        // By name.
        $byName = collect($this->listing(['q' => $token, 'limit' => 50])->json('data.data'))->pluck('id');
        $this->assertContains((string)$parent->id, $byName->map(function ($v) { return (string)$v; })->all());

        // By slug — the case that found nothing at all before.
        $bySlug = collect($this->listing(['q' => 'child-' . $token, 'limit' => 50])->json('data.data'))->pluck('id');
        $this->assertContains((string)$child->id, $bySlug->map(function ($v) { return (string)$v; })->all(),
            'Searching by slug did not find the category.');

        // By parent name.
        $byParent = collect($this->listing(['q' => 'Parent ' . $token, 'limit' => 50])->json('data.data'))->pluck('id');
        $this->assertContains((string)$child->id, $byParent->map(function ($v) { return (string)$v; })->all(),
            'Searching by parent name did not find the child.');
    }

    public function test_the_list_returns_subcategories_at_all()
    {
        $this->admin();

        $parent = $this->makeCategory();
        $child = $this->makeCategory(['parent' => $parent->id]);

        $ids = collect($this->listing(['limit' => 200])->json('data.data'))
            ->pluck('id')->map(function ($v) { return (string)$v; });

        $this->assertContains((string)$child->id, $ids->all(),
            'A subcategory never appears in the list, which is how it used to be.');
    }

    public function test_status_scope_and_parent_filters()
    {
        $this->admin();

        $parent = $this->makeCategory(['status' => 1]);
        $child = $this->makeCategory(['parent' => $parent->id, 'status' => 2]);

        $roots = collect($this->listing(['scope' => 'root', 'limit' => 200])->json('data.data'));
        $this->assertNotContains((string)$child->id, $roots->pluck('id')->map(function ($v) { return (string)$v; })->all());

        $subs = collect($this->listing(['scope' => 'sub', 'limit' => 200])->json('data.data'));
        $this->assertContains((string)$child->id, $subs->pluck('id')->map(function ($v) { return (string)$v; })->all());
        $this->assertNotContains((string)$parent->id, $subs->pluck('id')->map(function ($v) { return (string)$v; })->all());

        $inactive = collect($this->listing(['status' => 2, 'limit' => 200])->json('data.data'));
        $this->assertTrue($inactive->every(function ($row) {
            return (int)$row['status'] === 2;
        }), 'The status filter returned a category of the other status.');

        // Filtering by parent returns the branch, the parent included.
        $branch = collect($this->listing(['parent' => $parent->id, 'limit' => 200])->json('data.data'))
            ->pluck('id')->map(function ($v) { return (string)$v; });

        $this->assertContains((string)$child->id, $branch->all());
        $this->assertContains((string)$parent->id, $branch->all());
    }

    public function test_sorting_and_pagination_agree_with_each_other()
    {
        $this->admin();

        $first = $this->listing(['orderby' => 'title', 'type' => 'asc', 'limit' => 5, 'page' => 1])
            ->json('data');

        $this->assertSame(1, (int)$first['current_page']);
        $this->assertLessThanOrEqual(5, count($first['data']));

        if ((int)$first['last_page'] < 2) {
            $this->markTestSkipped('Not enough categories to page through.');
        }

        $second = $this->listing(['orderby' => 'title', 'type' => 'asc', 'limit' => 5, 'page' => 2])
            ->json('data');

        $firstIds = collect($first['data'])->pluck('id')->map(function ($v) { return (string)$v; });
        $secondIds = collect($second['data'])->pluck('id')->map(function ($v) { return (string)$v; });

        // A row may not appear on two pages — which is what an unstable sort
        // over equal values produces.
        $this->assertEmpty($firstIds->intersect($secondIds)->all(),
            'The same category appeared on two pages.');

        // Titles keep ascending across the page boundary.
        $lastOfFirst = collect($first['data'])->last()['title'];
        $firstOfSecond = collect($second['data'])->first()['title'];

        $this->assertLessThanOrEqual(0, strcasecmp($lastOfFirst, $firstOfSecond),
            'Ordering did not survive the page boundary.');
    }

    public function test_an_unknown_sort_column_falls_back_instead_of_reaching_the_database()
    {
        $this->admin();

        $response = $this->listing(['orderby' => 'categories.admin_id; DROP TABLE categories']);

        $this->assertSame(200, (int)$response->json('status'));
        $this->assertTrue(\Illuminate\Support\Facades\Schema::hasTable('categories'));
    }

    // ---- 19-22. deleting ----------------------------------------------------

    public function test_deleting_a_category_without_products_removes_only_it()
    {
        $this->admin();

        $category = $this->makeCategory();

        $response = $this->deleteJson('/api/admin/category/delete/' . $category->id);

        $this->assertSame(200, (int)$response->json('status'), json_encode($response->json()));
        $this->assertNull(Category::find($category->id));
    }

    /**
     * The bug this module was carrying: children were set to `parent = 0`,
     * which is neither NULL (a root) nor a real id, so they vanished from
     * every listing and could only be found in the database.
     */
    public function test_deleting_a_parent_re_parents_its_children_rather_than_orphaning_them()
    {
        $this->admin();

        $grandparent = $this->makeCategory();
        $parent = $this->makeCategory(['parent' => $grandparent->id]);
        $child = $this->makeCategory(['parent' => $parent->id]);

        $this->deleteJson('/api/admin/category/delete/' . $parent->id)->assertOk();

        $fresh = $child->fresh();

        $this->assertNotNull($fresh, 'The child was deleted along with its parent.');
        $this->assertSame((int)$grandparent->id, (int)$fresh->parent,
            'The child was not moved up to its grandparent.');
        $this->assertNotSame(0, (int)$fresh->parent, 'The child was orphaned onto parent = 0.');

        // And it is findable again.
        $ids = collect($this->listing(['limit' => 200])->json('data.data'))->pluck('id')->map(function ($v) { return (string)$v; });
        $this->assertContains((string)$child->id, $ids->all());
    }

    public function test_children_can_be_deleted_with_their_parent_or_the_delete_refused()
    {
        $this->admin();

        $parent = $this->makeCategory();
        $child = $this->makeCategory(['parent' => $parent->id]);

        // Refused while children exist.
        $blocked = $this->deleteJson('/api/admin/category/delete/' . $parent->id . '?children=block');

        $this->assertSame(201, (int)$blocked->json('status'));
        $this->assertNotNull(Category::find($parent->id), 'A blocked delete removed the category anyway.');

        // Or taken down together.
        $this->deleteJson('/api/admin/category/delete/' . $parent->id . '?children=cascade')->assertOk();

        $this->assertNull(Category::find($parent->id));
        $this->assertNull(Category::find($child->id), 'Cascade left the child behind.');
    }

    public function test_the_delete_impact_endpoint_reports_what_would_be_affected()
    {
        $this->admin();

        $parent = $this->makeCategory();
        $child = $this->makeCategory(['parent' => $parent->id]);

        $product = Product::first();

        if ($product) {
            ProductCategory::create([
                'category_id' => $parent->id, 'product_id' => $product->id, 'primary' => 0,
            ]);
        }

        $impact = $this->getJson('/api/admin/category/delete-impact/' . $parent->id)->json('data');

        $this->assertSame(1, (int)$impact['categories']);
        $this->assertSame(1, (int)$impact['subcategories']);
        $this->assertContains((int)$child->id, array_map('intval', $impact['subcategory_ids']));

        if ($product) {
            $this->assertSame(1, (int)$impact['products'],
                'The impact report did not mention the product that would be unlinked.');
        }
    }

    public function test_deleting_a_category_with_products_unlinks_them_without_deleting_them()
    {
        $this->admin();

        $product = Product::first();

        if (!$product) {
            $this->markTestSkipped('No product to attach.');
        }

        $category = $this->makeCategory();

        ProductCategory::create([
            'category_id' => $category->id, 'product_id' => $product->id, 'primary' => 0,
        ]);

        $this->deleteJson('/api/admin/category/delete/' . $category->id)->assertOk();

        $this->assertSame(0, ProductCategory::where('category_id', $category->id)->count());
        $this->assertNotNull(Product::find($product->id), 'Deleting a category deleted its product.');
    }

    public function test_a_bulk_delete_that_names_a_missing_id_changes_nothing()
    {
        $this->admin();

        $category = $this->makeCategory();

        $response = $this->deleteJson('/api/admin/category/delete/' . $category->id . ',999999999');

        $this->assertNotSame(200, (int)$response->json('status'));
        $this->assertNotNull(Category::find($category->id),
            'A partially invalid bulk delete removed some rows before failing.');
    }

    // ---- 23-25. hierarchy rules --------------------------------------------

    public function test_a_category_cannot_be_its_own_parent()
    {
        $this->admin();

        $category = $this->makeCategory();

        $viaForm = $this->postJson('/api/admin/category/action/' . $category->id,
            $this->payload(['slug' => $category->slug, 'parent' => $category->id]));

        $this->assertSame(201, (int)$viaForm->json('status'));

        $viaEndpoint = $this->postJson('/api/admin/category/update-parent', [
            'id' => $category->id, 'parent_id' => $category->id,
        ]);

        $this->assertSame(201, (int)$viaEndpoint->json('status'),
            'The re-parent endpoint allowed a category to become its own parent.');

        $this->assertNull($category->fresh()->parent);
    }

    public function test_a_circular_relationship_is_refused()
    {
        $this->admin();

        $a = $this->makeCategory();
        $b = $this->makeCategory(['parent' => $a->id]);

        // Making A a child of B closes the loop.
        $response = $this->postJson('/api/admin/category/update-parent', [
            'id' => $a->id, 'parent_id' => $b->id,
        ]);

        $this->assertSame(201, (int)$response->json('status'),
            'A -> B -> A was accepted.');

        $this->assertNull($a->fresh()->parent);
        $this->assertSame((int)$a->id, (int)$b->fresh()->parent);
    }

    public function test_a_parent_that_does_not_exist_is_refused()
    {
        $this->admin();

        $response = $this->create(['parent' => 999999999]);

        $this->assertSame(201, (int)$response->json('status'));
    }

    public function test_nesting_is_capped()
    {
        $this->admin();

        $level1 = $this->makeCategory();
        $level2 = $this->makeCategory(['parent' => $level1->id]);
        $level3 = $this->makeCategory(['parent' => $level2->id]);

        // A fourth level is one past the cap.
        $response = $this->create(['parent' => $level3->id]);

        $this->assertSame(201, (int)$response->json('status'),
            'Nesting went past the documented maximum depth.');
    }

    public function test_the_parent_picker_excludes_the_category_and_its_descendants()
    {
        $this->admin();

        $parent = $this->makeCategory();
        $child = $this->makeCategory(['parent' => $parent->id]);

        $options = collect($this->getJson(
            '/api/admin/category/all-categories?scope=all&exclude=' . $parent->id
        )->json('data'))->pluck('id')->map(function ($v) { return (string)$v; });

        $this->assertNotContains((string)$parent->id, $options->all(),
            'The picker offered the category itself as its own parent.');
        $this->assertNotContains((string)$child->id, $options->all(),
            'The picker offered a descendant, which would create a loop.');
    }

    // ---- 26. authorization --------------------------------------------------

    public function test_every_category_endpoint_refuses_an_unauthenticated_caller()
    {
        $category = $this->makeCategory();

        $this->asGuest();

        $calls = [
            ['getJson', '/api/admin/category/all'],
            ['getJson', '/api/admin/category/all-categories'],
            ['getJson', '/api/admin/category/find/' . $category->id],
            ['getJson', '/api/admin/category/slug-available?slug=anything'],
            ['getJson', '/api/admin/category/delete-impact/' . $category->id],
            ['postJson', '/api/admin/category/action'],
            ['postJson', '/api/admin/category/update-parent'],
            ['postJson', '/api/admin/category/update-status'],
            ['deleteJson', '/api/admin/category/delete/' . $category->id],
        ];

        foreach ($calls as [$method, $uri]) {

            $response = $this->{$method}($uri, []);

            $this->assertNotSame(200, (int)$response->json('status'),
                "$uri answered an anonymous caller.");
        }

        // Nothing an anonymous caller asked for happened.
        $this->assertNotNull(Category::find($category->id));
    }

    /**
     * The endpoint that had no permission check at all. A role without
     * category.edit could re-parent anything in the shop.
     */
    public function test_the_re_parent_endpoint_requires_the_edit_permission()
    {
        $admin = $this->admin();

        $parent = $this->makeCategory();
        $child = $this->makeCategory();

        $role = $admin->roles()->first();

        if (!$role) {
            $this->markTestSkipped('The superadmin has no role to strip.');
        }

        $permission = \Spatie\Permission\Models\Permission::where('name', 'category.edit')->first();

        if (!$permission) {
            $this->markTestSkipped('category.edit is not a registered permission.');
        }

        $role->revokePermissionTo($permission);
        app()[\Spatie\Permission\PermissionRegistrar::class]->forgetCachedPermissions();

        try {
            $this->admin();

            $response = $this->postJson('/api/admin/category/update-parent', [
                'id' => $child->id, 'parent_id' => $parent->id,
            ]);

            $this->assertNotSame(200, (int)$response->json('status'),
                'Re-parenting went through without category.edit.');

            $this->assertNull($child->fresh()->parent);

            $status = $this->postJson('/api/admin/category/update-status', [
                'id' => $child->id, 'status' => 2,
            ]);

            $this->assertNotSame(200, (int)$status->json('status'),
                'A status change went through without category.edit.');

        } finally {
            $role->givePermissionTo($permission);
            app()[\Spatie\Permission\PermissionRegistrar::class]->forgetCachedPermissions();
        }
    }

    /**
     * Both write endpoints used to hand `$request->all()` to the database, so
     * a caller could name any column — admin_id included — and have it written.
     */
    public function test_a_request_cannot_write_columns_the_form_does_not_own()
    {
        $admin = $this->admin();

        $category = $this->makeCategory();

        $other = Admin::where('id', '!=', $admin->id)->first();

        $this->postJson('/api/admin/category/action/' . $category->id,
            $this->payload([
                'slug' => $category->slug,
                'admin_id' => $other ? $other->id : 999,
                'id' => 123456789,
                'created_at' => '2000-01-01 00:00:00',
            ]))->assertOk();

        $fresh = $category->fresh();

        $this->assertNotNull($fresh, 'The id was rewritten from the request.');
        $this->assertSame((int)$category->admin_id, (int)$fresh->admin_id,
            'admin_id was written from the request body.');
    }

    // ---- 27-29. failure and empty states ------------------------------------

    public function test_a_missing_category_answers_not_found_rather_than_a_server_error()
    {
        $this->admin();

        foreach ([
            ['getJson', '/api/admin/category/find/999999999'],
            ['getJson', '/api/admin/category/delete-impact/999999999'],
            ['deleteJson', '/api/admin/category/delete/999999999'],
        ] as [$method, $uri]) {

            $response = $this->{$method}($uri);

            $response->assertOk();
            $this->assertNotSame(200, (int)$response->json('status'), $uri);
            $this->assertStringNotContainsStringIgnoringCase('SQLSTATE',
                json_encode($response->json()), "$uri leaked a database error.");
        }

        $edit = $this->postJson('/api/admin/category/action/999999999', $this->payload());

        $this->assertNotSame(200, (int)$edit->json('status'));
    }

    public function test_a_search_that_matches_nothing_returns_an_empty_page_not_an_error()
    {
        $this->admin();

        $response = $this->listing(['q' => 'no-such-category-' . uniqid()]);

        $this->assertSame(200, (int)$response->json('status'));
        $this->assertSame([], $response->json('data.data'));
        $this->assertSame(0, (int)$response->json('data.total'));

        // The stat cards still describe the whole catalogue, not the search.
        $this->assertGreaterThan(0, (int)$response->json('data.stats.total'));
    }

    // ---- stats and counts ---------------------------------------------------

    public function test_every_summary_figure_is_counted_from_the_database()
    {
        $this->admin();

        $parent = $this->makeCategory(['status' => 1]);
        $this->makeCategory(['parent' => $parent->id, 'status' => 2]);

        $stats = $this->listing(['limit' => 1])->json('data.stats');

        $this->assertSame(Category::count(), (int)$stats['total']);
        $this->assertSame(Category::whereNull('parent')->count(), (int)$stats['root_categories']);
        $this->assertSame(Category::whereNotNull('parent')->count(), (int)$stats['sub_categories']);
        $this->assertSame(Category::where('status', 1)->count(), (int)$stats['active_categories']);
        $this->assertSame(Category::where('status', 2)->count(), (int)$stats['inactive_categories']);

        // Root + sub is the total, which the old cards could not manage.
        $this->assertSame(
            (int)$stats['total'],
            (int)$stats['root_categories'] + (int)$stats['sub_categories']
        );

        $withProducts = Category::whereIn('id',
            ProductCategory::select('category_id')->distinct()
        )->count();

        $this->assertSame($withProducts, (int)$stats['with_products']);
    }

    public function test_the_row_counts_match_the_database()
    {
        $this->admin();

        $parent = $this->makeCategory();
        $this->makeCategory(['parent' => $parent->id]);
        $this->makeCategory(['parent' => $parent->id]);

        $product = Product::first();

        if ($product) {
            ProductCategory::create([
                'category_id' => $parent->id, 'product_id' => $product->id, 'primary' => 0,
            ]);
        }

        $row = collect($this->listing(['limit' => 200])->json('data.data'))
            ->firstWhere('id', (string)$parent->id)
            ?: collect($this->listing(['limit' => 200])->json('data.data'))
                ->first(function ($r) use ($parent) {
                    return (string)$r['id'] === (string)$parent->id;
                });

        $this->assertNotNull($row, 'The category is missing from the list.');
        $this->assertSame(2, (int)$row['children_count']);
        $this->assertSame($product ? 1 : 0, (int)$row['products_count']);
        $this->assertNotEmpty($row['image_url'], 'No image URL for the row.');
    }

    public function test_a_child_row_carries_its_parent_name()
    {
        $this->admin();

        $parent = $this->makeCategory(['title' => 'Named Parent ' . uniqid()]);
        $child = $this->makeCategory(['parent' => $parent->id]);

        $row = collect($this->listing(['scope' => 'sub', 'limit' => 200])->json('data.data'))
            ->first(function ($r) use ($child) {
                return (string)$r['id'] === (string)$child->id;
            });

        $this->assertNotNull($row);
        $this->assertSame($parent->title, $row['parent_title'],
            'The list did not resolve the parent name, so the column showed a bare id.');
    }

    // ---- performance --------------------------------------------------------

    /**
     * The list must cost a fixed number of queries whatever is on the page.
     *
     * The old one eager-loaded `child`, whose relation eager-loads `child`
     * again, so the query count climbed with the depth and breadth of the tree
     * and every row dragged its whole subtree back with it — to render two
     * levels.
     */
    public function test_the_list_does_not_issue_a_query_per_row()
    {
        $this->admin();

        // Enough rows that an N+1 would be unmistakable.
        $parent = $this->makeCategory();
        for ($i = 0; $i < 6; $i++) {
            $this->makeCategory(['parent' => $parent->id]);
        }

        $queries = [];

        DB::listen(function ($query) use (&$queries) {
            $queries[] = $query->sql;
        });

        $response = $this->listing(['limit' => 25]);

        DB::flushQueryLog();

        $this->assertSame(200, (int)$response->json('status'));
        $this->assertGreaterThan(5, count($response->json('data.data')));

        /*
         * One count for the paginator, one for the page, one grouped stats
         * query, one "with products" count — plus whatever the auth layer
         * needs. The counts per row are correlated subqueries inside the page
         * query, not extra round trips.
         */
        $categoryQueries = array_filter($queries, function ($sql) {
            return stripos($sql, 'categories') !== false
                || stripos($sql, 'product_categories') !== false;
        });

        $this->assertLessThanOrEqual(6, count($categoryQueries),
            "The list issued " . count($categoryQueries) . " category queries:\n"
            . implode("\n", $categoryQueries));
    }

    /**
     * The list response carries what the table renders and not the three TEXT
     * bodies per row that `select *` used to bring along.
     */
    public function test_the_list_payload_stays_lean()
    {
        $this->admin();

        $this->makeCategory([
            'description' => str_repeat('long body ', 500),
            'short_description' => str_repeat('short ', 200),
            'meta_description' => str_repeat('meta ', 200),
        ]);

        $row = collect($this->listing(['limit' => 50])->json('data.data'))->first();

        $this->assertArrayNotHasKey('description', $row, 'The list is shipping full descriptions.');
        $this->assertArrayNotHasKey('short_description', $row);

        // But everything the table shows is there.
        foreach ([
            'id', 'title', 'slug', 'parent', 'parent_title', 'image', 'image_url',
            'status', 'display_order', 'children_count', 'products_count',
            'created_at', 'updated_at',
        ] as $key) {
            $this->assertArrayHasKey($key, $row, "The list is missing $key.");
        }
    }

    // ---- the hierarchy helper on its own ------------------------------------

    public function test_the_tree_helper_survives_a_cycle_that_is_already_stored()
    {
        $this->admin();

        $a = $this->makeCategory();
        $b = $this->makeCategory(['parent' => $a->id]);

        // Written past the controller, the way the old endpoint would have.
        DB::table('categories')->where('id', $a->id)->update(['parent' => $b->id]);

        // Must terminate rather than recurse forever.
        $descendants = CategoryTree::descendantIds($a->id);

        $this->assertContains((int)$b->id, $descendants);
        $this->assertTrue(CategoryTree::wouldCycle($a->id, $b->id));

        DB::table('categories')->where('id', $a->id)->update(['parent' => null]);
    }

    // ---- the tree view ------------------------------------------------------

    /** The list as a hierarchy, keyed by title for readability in a failure. */
    private function tree($params = [])
    {
        $response = $this->listing(array_merge(['view' => 'tree', 'limit' => 500], $params));

        $this->assertSame(200, (int)$response->json('status'), json_encode($response->json()));

        return $response->json('data');
    }

    private function titlesInOrder($tree)
    {
        return array_map(function ($row) {
            return str_repeat('-', (int)$row['depth']) . $row['title'];
        }, $tree['data']);
    }

    private function findRow($tree, $title)
    {
        foreach ($tree['data'] as $row) {
            if ($row['title'] === $title) {
                return $row;
            }
        }

        return null;
    }

    public function test_the_tree_view_puts_each_child_directly_under_its_parent()
    {
        $this->admin();

        $parent = $this->makeCategory(['title' => 'QA Tree Parent']);
        $first = $this->makeCategory(['title' => 'QA Tree Child A', 'parent' => $parent->id]);
        $second = $this->makeCategory(['title' => 'QA Tree Child B', 'parent' => $parent->id]);

        $tree = $this->tree(['orderby' => 'title', 'type' => 'asc']);

        $order = $this->titlesInOrder($tree);

        $at = array_search('QA Tree Parent', $order, true);

        $this->assertNotFalse($at, 'The parent is not in the tree: ' . json_encode($order));

        // Immediately after it, and one level in — not merely somewhere in the
        // same response, which is all the flat list ever promised.
        $this->assertSame(
            ['-QA Tree Child A', '-QA Tree Child B'],
            array_slice($order, $at + 1, 2),
            'Children are not nested under their parent: ' . json_encode($order)
        );

        $row = $this->findRow($tree, 'QA Tree Parent');

        $this->assertSame(0, (int)$row['depth']);
        $this->assertSame(2, (int)$row['visible_children']);
        $this->assertSame(2, (int)$row['children_count']);
    }

    /**
     * The whole point of the view. Paginating the flat list puts a parent on
     * one page and its children on another, which is the problem the hierarchy
     * exists to solve — so a page has to be a page of parents.
     */
    public function test_a_page_of_the_tree_is_a_page_of_parents_with_their_branches_attached()
    {
        $this->admin();

        $parent = $this->makeCategory(['title' => 'QA Paging Parent']);
        $this->makeCategory(['title' => 'QA Paging Child A', 'parent' => $parent->id]);
        $this->makeCategory(['title' => 'QA Paging Child B', 'parent' => $parent->id]);

        $roots = Category::whereNull('parent')->count();

        $tree = $this->tree(['limit' => 500]);

        $this->assertSame($roots, (int)$tree['total'],
            'total should count top-level categories, not rows.');

        $this->assertGreaterThan((int)$tree['total'], (int)$tree['row_count'],
            'The branches should make more rows than there are parents.');

        $this->assertCount((int)$tree['row_count'], $tree['data']);

        // Every child in the response sits after a parent that is also in it.
        $seen = [];

        foreach ($tree['data'] as $row) {

            if ((int)$row['depth'] > 0) {
                $this->assertArrayHasKey((string)$row['parent'], $seen,
                    $row['title'] . ' appears before or without its parent.');
            }

            $seen[(string)$row['id']] = true;
        }

        // And a page holds whole branches, never half of one.
        $firstPage = $this->tree(['limit' => 2, 'page' => 1]);

        $parents = array_filter($firstPage['data'], function ($row) {
            return (int)$row['depth'] === 0;
        });

        $this->assertCount(2, $parents, 'A page of 2 should hold exactly 2 parents.');

        foreach ($firstPage['data'] as $row) {
            if ((int)$row['depth'] > 0) {
                $this->assertContains(
                    (string)$row['parent'],
                    array_map(function ($p) { return (string)$p['id']; }, $parents),
                    'A child was paged away from its parent.'
                );
            }
        }
    }

    /**
     * A search matches categories, not branches. Matching only a child would
     * leave it with nowhere to hang in a hierarchy, so its ancestors come too
     * — flagged, so the panel can show them as context rather than as hits.
     */
    public function test_a_search_for_a_child_brings_its_parent_along_as_context()
    {
        $this->admin();

        $suffix = uniqid();
        $parent = $this->makeCategory(['title' => 'QA Ctx Parent ' . $suffix]);
        $child = $this->makeCategory([
            'title' => 'Zzqacontext ' . $suffix,
            'parent' => $parent->id,
        ]);

        $tree = $this->tree(['q' => 'Zzqacontext']);

        $parentRow = $this->findRow($tree, 'QA Ctx Parent ' . $suffix);
        $childRow = $this->findRow($tree, 'Zzqacontext ' . $suffix);

        $this->assertNotNull($childRow, 'The match itself is missing.');
        $this->assertNotNull($parentRow, 'The match was returned with no parent to hang from.');

        $this->assertTrue((bool)$childRow['matched'], 'The hit should be marked as one.');
        $this->assertFalse((bool)$parentRow['matched'],
            'The parent is context, not a result, and saying otherwise makes the list look wrong.');

        $this->assertSame(0, (int)$parentRow['depth']);
        $this->assertSame(1, (int)$childRow['depth']);
    }

    /**
     * The counts have to say two different things: what the category has, and
     * how much of it survived the filters. Without the second, a parent whose
     * children were all filtered out is indistinguishable from one that has
     * none — and the panel would tell the admin it had no subcategories.
     */
    public function test_a_filtered_branch_reports_what_it_has_and_what_it_is_showing()
    {
        $this->admin();

        $parent = $this->makeCategory(['title' => 'QA Filter Parent', 'status' => 1]);
        $this->makeCategory(['title' => 'QA Filter Live', 'parent' => $parent->id, 'status' => 1]);
        $this->makeCategory(['title' => 'QA Filter Draft', 'parent' => $parent->id, 'status' => 2]);

        $all = $this->findRow($this->tree(), 'QA Filter Parent');

        $this->assertSame(2, (int)$all['children_count']);
        $this->assertSame(2, (int)$all['visible_children']);

        // Published only: the draft child drops out, the category still has it.
        $filtered = $this->findRow($this->tree(['status' => 1]), 'QA Filter Parent');

        $this->assertNotNull($filtered);
        $this->assertSame(2, (int)$filtered['children_count'], 'The real count should not move.');
        $this->assertSame(1, (int)$filtered['visible_children'], 'Only one child survived the filter.');
    }

    /**
     * The guides that draw the branch. They are the difference between a tree
     * and a list of indents, and they are decided here rather than in the
     * panel because only the server sees the whole branch.
     */
    public function test_tree_rows_carry_the_guides_that_draw_the_hierarchy()
    {
        $this->admin();

        $parent = $this->makeCategory(['title' => 'QA Guide Parent']);
        $this->makeCategory(['title' => 'QA Guide Child A', 'parent' => $parent->id]);
        $this->makeCategory(['title' => 'QA Guide Child B', 'parent' => $parent->id]);

        $tree = $this->tree(['orderby' => 'title', 'type' => 'asc']);

        $first = $this->findRow($tree, 'QA Guide Child A');
        $last = $this->findRow($tree, 'QA Guide Child B');
        $root = $this->findRow($tree, 'QA Guide Parent');

        $this->assertFalse((bool)$first['is_last_child'], 'A middle child continues the guide.');
        $this->assertTrue((bool)$last['is_last_child'], 'The last child ends it.');

        // One flag per level above the row.
        $this->assertSame([], $root['ancestor_lines']);
        $this->assertCount(1, $first['ancestor_lines']);

        // A root may take a subcategory; one at the nesting cap may not, and
        // the panel hides "Add subcategory" on the strength of this.
        $this->assertTrue((bool)$root['can_have_children']);
        $this->assertFalse((bool)$first['can_have_children']);
    }

    public function test_the_flat_view_is_still_what_the_endpoint_returns_by_default()
    {
        $this->admin();

        $parent = $this->makeCategory(['title' => 'QA Default Parent']);
        $this->makeCategory(['title' => 'QA Default Child', 'parent' => $parent->id]);

        $flat = $this->listing(['limit' => 500])->json('data');

        $this->assertSame('flat', $flat['view']);
        $this->assertSame(Category::count(), (int)$flat['total'],
            'The flat list counts every category, parents and children alike.');

        // None of the tree's own fields leak into it.
        $this->assertArrayNotHasKey('depth', $flat['data'][0]);

        // And the tree is a different shape of the same data.
        $tree = $this->tree();

        $this->assertSame('tree', $tree['view']);
        $this->assertSame(Category::count(), (int)$tree['row_count'],
            'An unfiltered tree should still show every category.');
    }

    public function test_a_tree_search_that_matches_nothing_is_an_empty_page_not_an_error()
    {
        $this->admin();

        $tree = $this->tree(['q' => 'zzz-no-such-category-' . uniqid()]);

        $this->assertSame([], $tree['data']);
        $this->assertSame(0, (int)$tree['total']);
        $this->assertSame(1, (int)$tree['last_page']);
        $this->assertNull($tree['from']);
    }
}
