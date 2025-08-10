<?php
/**
 * Author: Talemul Islam
 * Website: https://talemul.com
 */


namespace SuperAdmin\Admin\LogViewer;

use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Response;
use Illuminate\Support\Facades\File;
use SuperAdmin\Admin\Facades\Admin;
use SuperAdmin\Admin\Layout\Content;

class LogController extends Controller
{
    public function index(Request $request, $file = null)
    {
        if ($file === null) {
            $file = (new LogViewer())->getLastModifiedLog();
        }

        return Admin::content(function (Content $content) use ($file, $request) {
            $offset = $request->get('offset');

            $viewer = new LogViewer($file);

            // Fetch logs exactly as your Blade expects
            $logs = $viewer->fetch($offset);

            // Optional: keyword search across common fields
            $q = trim((string) $request->get('q', ''));
            if ($q !== '') {
                $needle = mb_strtolower($q);
                $logs = array_values(array_filter($logs, function ($row) use ($needle) {
                    $hay = mb_strtolower(
                        ($row['level'] ?? '') . ' ' .
                        ($row['env'] ?? '')   . ' ' .
                        ($row['time'] ?? '')  . ' ' .
                        ($row['info'] ?? '')  . ' ' .
                        ($row['trace'] ?? '')
                    );
                    return mb_strpos($hay, $needle) !== false;
                }));
            }

            $content->body(view('super-admin-logs::logs', [
                'logs'                           => $logs,
                // both keys, in case Blade uses either
                'logFiles'                       => $viewer->getLogFiles(),
                'files'                          => $viewer->getLogFiles(),
                // 👇 add this to satisfy $current in Blade
                'current'                        => $viewer->file,
                'downloadPath' => route('log-viewer-download', ['file' => $viewer->set_bypass($viewer->file)]),

                'fileName'                       => $viewer->file,
                'end'                            => $viewer->getFilesize(),
                'tailPath'                       => route('log-viewer-tail', ['file' => $viewer->file]),
                'prevUrl'                        => $viewer->getPrevPageUrl(),
                'nextUrl'                        => $viewer->getNextPageUrl(),
                'filePath'                       => $viewer->getFilePath(),
                'size'                           => static::bytesToHuman($viewer->getFilesize()),
                // optional numeric alias if Blade ever uses it
                'filesize'                       => $viewer->getFilesize(),

                'bypass_protected_urls_find'     => $viewer->bypass_protected_urls_find,
                'bypass_protected_urls_replace'  => $viewer->bypass_protected_urls_replace,
                // keep search term (if you added a search box)
                'q'                              => $q ?? '',
            ]));

            $content->header($viewer->getFilePath());
        });
    }

    public function tail(Request $request, $file)
    {
        $offset = $request->get('offset');
        $viewer = new LogViewer($file);

        [$pos, $logs] = $viewer->tail($offset);

        return compact('pos', 'logs');
    }

    // NEW: Download current log file
    public function download($file)
    {
        $viewer = new LogViewer($file);
        $path   = $viewer->getFilePath();

        if (!is_file($path)) {
            abort(404, 'Log file not found');
        }

        // Force the browser to download instead of render
        return response()->streamDownload(function () use ($path) {
            $fp = fopen($path, 'rb');
            while (!feof($fp)) {
                echo fread($fp, 8192);
            }
            fclose($fp);
        }, basename($path), [
            'Content-Type'            => 'application/octet-stream',
            'Content-Length'          => (string) filesize($path),
            'Content-Disposition'     => 'attachment; filename="'.basename($path).'"',
            'X-Content-Type-Options'  => 'nosniff',
        ]);
    }



    // NEW: Delete current log file (with CSRF + method spoof from Blade)
    public function destroy($file)
    {
        $viewer = new LogViewer($file);
        $path = $viewer->getFilePath();

        if (!file_exists($path)) {
            return redirect()
                ->route('log-viewer-index')
                ->with('error', 'Log file not found.');
        }

        // Try to remove
        @unlink($path);

        return redirect()
            ->route('log-viewer-index')
            ->with('status', 'Log file deleted.');
    }

    protected static function bytesToHuman($bytes)
    {
        $units = ['B', 'KB', 'MB', 'GB', 'TB', 'PB'];

        for ($i = 0; $bytes > 1024; $i++) {
            $bytes /= 1024;
        }

        return round($bytes, 2) . ' ' . $units[$i];
    }
}
