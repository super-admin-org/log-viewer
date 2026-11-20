@if(!empty($summary))
    @include('super-admin-logs::summary')
@endif

<div class="row">
    <div class="col-md-10">
        <div class="card">
            <div class="card-header">
                <button type="button" class="btn btn-primary btn-sm log-refresh"><i
                            class="icon-refresh"></i> {{ trans('admin.refresh') }}</button>
                <button type="button" class="btn btn-light btn-sm log-live"><i id="live-indicator"
                                                                               class="icon-play"></i></button>
                <div class="float-end">
                    <form method="GET"
                          action="{{ $current ? route('log-viewer-file', ['file' => $current]) : route('log-viewer-index') }}"
                          class="d-inline-block me-2">
                        @if(!empty($level))
                            <input type="hidden" name="level" value="{{ $level }}">
                        @endif
                        <div class="input-group input-group-sm">
                            <input name="q" value="{{ $q ?? '' }}" type="text" class="form-control"
                                   placeholder="Search logs...">
                            <button class="btn btn-primary btn-sm" type="submit">
                                <i class="icon-search"></i>
                            </button>
                        </div>
                    </form>

                    @if(!empty($downloadPath))
                        <a class="btn btn-light btn-sm" href="{{ $downloadPath }}" download target="_blank">
                            <i class="icon-download"></i> Download
                        </a>

                        <form method="POST"
                              action="{{ route('log-viewer-destroy', ['file' => $current]) }}"
                              class="d-inline-block"
                              onsubmit="return confirm('Delete this log file permanently?');">
                            @csrf
                            @method('DELETE')
                            <button class="btn btn-danger btn-sm" type="submit">
                                <i class="icon-trash"></i> Delete
                            </button>
                        </form>
                    @endif

                    @php
                        // Keep the search term and the level filter across pages.
                        $carry = function ($url) {
                            $extra = array_filter(['q' => request('q'), 'level' => request('level')]);

                            if (!$extra) {
                                return $url;
                            }

                            return $url . (Str::contains($url, '?') ? '&' : '?') . http_build_query($extra);
                        };
                    @endphp
                    <div class="btn-group">
                        @if ($prevUrl)
                            <a href="{{ $carry($prevUrl) }}"
                               class="btn btn-light btn-sm"><i class="icon-chevron-left"></i></a>
                        @endif
                        @if ($nextUrl)
                            <a href="{{ $carry($nextUrl) }}"
                               class="btn btn-light btn-sm"><i class="icon-chevron-right"></i></a>
                        @endif

                    </div>
                </div>
            </div>

            <div class="card-body p-0">
                <div class="table-responsive">
                    <table class="table table-hover log-table mb-0">
                        <thead>
                        <tr>
                            <th>Level</th>
                            <th>Env</th>
                            <th>Time</th>
                            <th>Message</th>
                            <th></th>
                        </tr>
                        </thead>
                        <tbody>

                        @if(empty($logs))
                            <tr>
                                <td colspan="5" class="text-center text-muted" style="padding:20px;">
                                    @if(!empty($level) || !empty($q))
                                        No entries match this filter in the current page window.
                                        <a href="{{ $current ? route('log-viewer-file', ['file' => $current]) : route('log-viewer-index') }}">Clear filters</a>
                                    @else
                                        This log file is empty.
                                    @endif
                                </td>
                            </tr>
                        @endif

                        @foreach($logs as $index => $log)
                            <tr>
                                <td>
                                    <span class="badge bg-{{ \SuperAdmin\Admin\LogViewer\LogViewer::$levelColors[$log['level']] ?? 'secondary' }}">{{ $log['level'] }}</span>
                                </td>
                                <td><strong>{{ $log['env'] }}</strong></td>
                                <td style="width:150px;">{{ $log['time'] }}</td>
                                <td><code style="word-break: break-all;">{{ $log['info'] }}</code></td>
                                <td>
                                    @if(!empty($log['trace']))
                                        <a class="btn btn-primary btn-xs"
                                           data-bs-toggle="collapse" data-bs-target="#trace-{{$index}}"><i
                                                    class="icon-info"></i>&nbsp;Exception&nbsp;</a>
                                    @endif
                                </td>
                            </tr>
                            @if (!empty($log['trace']))
                                <tr>
                                    <td colspan="5" class="p-0 border-0">
                                        <div class="collapse" id="trace-{{$index}}"
                                             style="white-space: pre-wrap;background: #333;color: #fff; padding: 10px;">{{ $log['trace'] }}</div>
                                    </td>
                                </tr>
                            @endif
                        @endforeach

                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>

    <div class="col-md-2">

        <div class="card">
            <div class="card-header">
                <h3 class="card-title">Files</h3>
            </div>
            <div class="card-body p-0">
                <div class="list-group list-group-flush">
                    @foreach($logFiles as $logFile)
                        <a href="{{ route('log-viewer-file', ['file' => str_replace($bypass_protected_urls_find,$bypass_protected_urls_replace,$logFile)]) }}"
                           class="list-group-item list-group-item-action text-break {{ $logFile == $fileName ? 'active' : '' }}">
                            <i class="icon-{{ ($logFile == $fileName) ? 'folder-open' : 'folder' }} me-1"></i>{{ $logFile }}
                        </a>
                    @endforeach
                </div>
            </div>
        </div>

        <div class="card mt-4">
            <div class="card-header">
                <h3 class="card-title">Info</h3>
            </div>
            <div class="card-body p-0">
                <ul class="list-group list-group-flush">
                    <li class="list-group-item small">Size: {{ $size }}</li>
                    <li class="list-group-item small">Updated at: {{ date('Y-m-d H:i:s', filectime($filePath)) }}</li>
                </ul>
            </div>
        </div>
    </div>
</div>
<script data-exec-on-popstate>

    (function () {

        document.querySelector('.log-refresh').addEventListener('click', function () {
            admin.ajax.reload();
        });

        var pos = {{ $end }};

        function changePos(offset) {
            pos = offset;
        }

        function fetch() {
            var url = '{{ $tailPath }}';
            var data = {offset: pos}
            admin.ajax.get(url, data, function (response) {
                var logs = response.data.logs;
                var body = document.querySelector('.log-table > tbody');
                for (var i = logs.length - 1; i >= 0; i--) {
                    body.insertAdjacentHTML('afterbegin', logs[i]);
                }
                changePos(data.pos);
            });
        }

        document.querySelector('.log-live').addEventListener("click", function (event) {

            if (typeof (window.logViewerState) == 'undefined' || window.logViewerState == 'pause') {
                document.querySelector("#live-indicator").classList.add("icon-pause")
                document.querySelector("#live-indicator").classList.remove("icon-play");

                window.logViewerState = "play";
                setTimeout(function () {
                    document.addEventListener("click", clickDisableLive);
                }, 10);
            } else {
                document.querySelector("#live-indicator").classList.remove("icon-pause")
                document.querySelector("#live-indicator").classList.add("icon-play");
                window.logViewerState = "pause";
                disableLive();
            }

            window.refreshTimeout = setTimeout(function () {
                admin.ajax.reload();
            }, 5000);
        });

        function clickDisableLive(event) {

            if (event.target.getAttribute("id") != "live-indicator") {
                disableLive();
            }
        }

        function disableLive() {
            window.logViewerState = "pause";
            document.removeEventListener("click", clickDisableLive);
            document.querySelector("#live-indicator").classList.add("icon-play")
            document.querySelector("#live-indicator").classList.remove("icon-pause");
            clearTimeout(window.refreshTimeout);
        }

        if (window.logViewerState == "play") {
            document.querySelector("#live-indicator").classList.remove("icon-play")
            document.querySelector("#live-indicator").classList.add("icon-pause");

            window.refreshTimeout = setTimeout(function () {
                admin.ajax.reload();
            }, 5000);
        }
    })();

</script>
