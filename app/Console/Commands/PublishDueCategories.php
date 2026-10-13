<?php

namespace App\Console\Commands;

use App\Models\Category;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Log;

/**
 * Publish categories whose scheduled time has arrived.
 *
 * The category form has always collected a "Schedule Publishing" date, the
 * field has always saved it, and nothing in the application ever read the
 * column again -- no command, no query, no storefront filter. An admin could
 * schedule a category for next Monday, come back on Tuesday, and find it
 * still sitting in Draft, because the date was only ever a note the record
 * kept to itself.
 *
 * This is the half that was missing. It is deliberately one-directional: it
 * publishes, and it never unpublishes. A date that has passed means "should
 * be live by now", and nothing about the field implies an end.
 *
 * `publish_at` is cleared as the row is published, which is what makes the
 * sweep safe to run every five minutes forever. Left in place, an admin who
 * moved the category back to Draft a week later would find it published
 * again on the next tick, by an instruction they had already carried out.
 */
class PublishDueCategories extends Command
{
    protected $signature = 'categories:publish-due';

    protected $description = 'Publish categories whose scheduled publishing time has arrived';

    public function handle()
    {
        $published = self::sweep();

        $this->info($published === 0
            ? 'No categories are due to be published.'
            : "Published {$published} scheduled categor" . ($published === 1 ? 'y.' : 'ies.'));

        return 0;
    }

    /**
     * Now, in the format `publish_at` is stored and compared in.
     *
     * The application's timezone, never the database's. MySQL's session
     * timezone is more than five hours off the app's in this deployment, so
     * a comparison made with SQL NOW() would publish a schedule half a day
     * early -- the same trap ExpireFlashSales documents.
     */
    public static function now()
    {
        return date('Y-m-d H:i:s');
    }

    /**
     * The sweep itself, so the admin list can run it too.
     *
     * Only DRAFT rows are touched. A category the admin has already
     * published needs nothing doing, and one they have deliberately taken
     * down is not ours to put back up.
     *
     * @return int how many categories were published
     */
    public static function sweep()
    {
        $due = Category::where('status', Config::get('constants.status.PRIVATE'))
            ->whereNotNull('publish_at')
            ->where('publish_at', '<=', self::now())
            ->get();

        if ($due->isEmpty()) {
            return 0;
        }

        Category::whereIn('id', $due->pluck('id'))->update([
            'status' => Config::get('constants.status.PUBLIC'),
            'publish_at' => null,
        ]);

        Log::info('Scheduled categories published', [
            'count' => $due->count(),
            'ids' => $due->pluck('id')->all(),
        ]);

        return $due->count();
    }
}
