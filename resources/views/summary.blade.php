@php
    $levelColors = \SuperAdmin\Admin\LogViewer\LogViewer::$levelColors;
    $severity    = \SuperAdmin\Admin\LogViewer\LogAnalyzer::$severity;
    $level       = $level ?? '';
    $q           = $q ?? '';

    $counts = $summary['levels'];
    uksort($counts, function ($a, $b) use ($severity) {
        return ($severity[$a] ?? 99) <=> ($severity[$b] ?? 99);
    });

    $baseUrl = $current
        ? route('log-viewer-file', ['file' => $current])
        : route('log-viewer-index');

    $levelUrl = function ($lvl) use ($baseUrl, $q) {
        $params = array_filter(['level' => $lvl, 'q' => $q]);
        return $params ? $baseUrl . '?' . http_build_query($params) : $baseUrl;
    };

    // Route and SQL come for free out of Laravel's own exception messages, no
    // app change needed. The URL/method/payload block is the one piece that
    // genuinely requires the app to add context - only its presence means
    // that has already been done, so only it should silence the pointer below.
    $hasRequestContext = false;
    foreach ($summary['issues'] as $issue) {
        if (!empty($issue['request'])) {
            $hasRequestContext = true;
            break;
        }
    }
@endphp

<div class="row">
    <div class="col-md-12">
        <div class="card card-primary">
            <div class="card-header with-border">
                <h3 class="card-title" style="font-weight:600;">
                    <i class="icon-info"></i>&nbsp;Summary
                </h3>
                <div class="float-end small text-muted">
                    @if($summary['truncated'])
                        last {{ round($summary['scanned'] / 1048576, 1) }} MB of {{ $size }} analysed
                    @else
                        whole file analysed ({{ $size }})
                    @endif
                    @if($summary['firstSeen'])
                        &middot; {{ $summary['firstSeen'] }} &rarr; {{ $summary['lastSeen'] }}
                    @endif
                </div>
            </div>

            <div class="card-body">
                @if($summary['total'] === 0)
                    <p class="text-muted mb-0">No parsable log entries in this file.</p>
                @else
                    {{-- Level breakdown. Each chip filters the table below. --}}
                    <div class="mb-2">
                        <a href="{{ $levelUrl(null) }}"
                           class="badge bg-{{ $level === '' ? 'primary' : 'secondary' }} text-decoration-none me-1"
                           style="padding:6px 10px;">
                            All <strong>{{ $summary['total'] }}</strong>
                        </a>
                        @foreach($counts as $lvl => $count)
                            <a href="{{ $levelUrl($lvl) }}"
                               class="badge bg-{{ $levelColors[$lvl] ?? 'secondary' }} text-decoration-none me-1"
                               style="padding:6px 10px;{{ $level === $lvl ? '' : 'opacity:.75;' }}">
                                {{ $lvl }} <strong>{{ $count }}</strong>
                            </a>
                        @endforeach
                    </div>

                    @if(empty($summary['issues']))
                        <p class="text-muted mb-0">
                            <i class="icon-info"></i>
                            No errors or warnings in the analysed window - nothing to act on.
                        </p>
                    @else
                        <div class="small text-muted mb-2">
                            {{ $summary['alerts'] }}
                            {{ \Illuminate\Support\Str::plural('entry', $summary['alerts']) }}
                            at WARNING or above, grouped into
                            <strong>{{ $summary['issueCount'] }}</strong>
                            distinct {{ \Illuminate\Support\Str::plural('issue', $summary['issueCount']) }}{{ $summary['issueCount'] > count($summary['issues']) ? ', top ' . count($summary['issues']) . ' shown' : '' }}:
                        </div>

                        @unless($hasRequestContext)
                            <div class="small text-muted mb-2" style="border-left:3px solid #dee2e6;padding-left:8px;">
                                Want the request URL, method and submitted data attached to errors like
                                these? See the README for a small addition to your app's exception
                                handler - once added, this panel picks it up automatically.
                            </div>
                        @endunless

                        <div class="list-group">
                            @foreach($summary['issues'] as $i => $issue)
                                @php $fixId = 'log-fix-' . $i; @endphp
                                <div class="list-group-item" style="border-left:3px solid var(--bs-{{ $levelColors[$issue['level']] ?? 'secondary' }}, #6c757d);">
                                    <div class="d-flex justify-content-between align-items-start">
                                        <div style="min-width:0;">
                                            <span class="badge bg-{{ $levelColors[$issue['level']] ?? 'secondary' }}">{{ $issue['level'] }}</span>
                                            <span class="badge bg-dark">&times;{{ $issue['count'] }}</span>
                                            @if($issue['exception'])
                                                <span class="badge bg-light text-dark">{{ class_basename($issue['exception']) }}</span>
                                            @endif
                                            <div class="mt-1">
                                                <code style="word-break:break-all;">{{ \Illuminate\Support\Str::limit($issue['message'], 220) }}</code>
                                            </div>
                                            <div class="small text-muted mt-1">
                                                @if($issue['location'])
                                                    <i class="icon-folder"></i> {{ $issue['location'] }} &middot;
                                                @endif
                                                first {{ $issue['first'] }} &middot; last {{ $issue['last'] }}
                                            </div>

                                            @if(!empty($issue['route']) || !empty($issue['request']))
                                                <div class="small text-muted mt-1">
                                                    @if(!empty($issue['route']['route']))
                                                        <span class="badge bg-light text-dark">Route: {{ $issue['route']['route'] }}</span>
                                                    @endif
                                                    @if(!empty($issue['route']['uri']))
                                                        <span class="badge bg-light text-dark">URI: {{ $issue['route']['uri'] }}</span>
                                                    @endif
                                                    @if(!empty($issue['request']['method']) || !empty($issue['request']['url']))
                                                        <span class="badge bg-light text-dark">{{ trim(($issue['request']['method'] ?? '') . ' ' . ($issue['request']['url'] ?? '')) }}</span>
                                                    @endif
                                                    @if(!empty($issue['request']['ip']))
                                                        <span class="badge bg-light text-dark">IP: {{ $issue['request']['ip'] }}</span>
                                                    @endif
                                                </div>
                                            @endif

                                            @if(!empty($issue['sql']))
                                                <div class="small mt-1">
                                                    <code style="word-break:break-all;">SQL: {{ \Illuminate\Support\Str::limit($issue['sql'], 200) }}</code>
                                                </div>
                                            @endif

                                            @if(!empty($issue['request']['payload']))
                                                <div class="small mt-1">
                                                    <code style="word-break:break-all;">Payload: {{ \Illuminate\Support\Str::limit(json_encode($issue['request']['payload'], JSON_UNESCAPED_SLASHES), 200) }}</code>
                                                </div>
                                            @endif
                                        </div>
                                        <a class="btn btn-primary btn-xs flex-shrink-0 ms-2"
                                           data-bs-toggle="collapse" data-bs-target="#{{ $fixId }}"
                                           style="white-space:nowrap;">
                                            <i class="icon-info"></i>&nbsp;How to fix
                                        </a>
                                    </div>

                                    <div class="collapse mt-2" id="{{ $fixId }}">
                                        <div style="background:#f6f8fa;border:1px solid #e1e4e8;border-radius:4px;padding:10px;">
                                            <strong>{{ $issue['solution']['title'] }}</strong>
                                            @if($issue['solution']['confidence'] === 'generic')
                                                <span class="badge bg-secondary">no known signature</span>
                                            @endif
                                            <p class="mb-2 mt-1">{{ $issue['solution']['cause'] }}</p>

                                            @if(!empty($issue['solution']['steps']))
                                                <ol class="mb-2 ps-3">
                                                    @foreach($issue['solution']['steps'] as $step)
                                                        <li>{{ $step }}</li>
                                                    @endforeach
                                                </ol>
                                            @endif

                                            @if(!empty($issue['solution']['commands']))
                                                @php
                                                    $shell = implode("\n", array_map(function ($command) {
                                                        return '$ ' . $command;
                                                    }, $issue['solution']['commands']));
                                                @endphp
                                                <div style="white-space:pre-wrap;background:#333;color:#fff;padding:8px;border-radius:3px;">{{ $shell }}</div>
                                            @endif

                                            <div class="small text-muted mt-2">
                                                Suggestions are pattern-based - confirm against the stack trace before acting.
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            @endforeach
                        </div>
                    @endif

                    @if(!empty($summary['activity']))
                        <div class="mt-3">
                            <a class="btn btn-light btn-sm" data-bs-toggle="collapse" data-bs-target="#log-activity"
                               style="border:1px solid #dee2e6;">
                                <i class="icon-info"></i>&nbsp;Info &amp; Debug activity
                                ({{ $summary['activityCount'] }} distinct{{ $summary['activityCount'] > count($summary['activity']) ? ', top ' . count($summary['activity']) . ' shown' : '' }})
                            </a>

                            <div class="collapse mt-2" id="log-activity">
                                <div class="table-responsive">
                                    <table class="table table-sm table-hover mb-0">
                                        <thead>
                                        <tr>
                                            <th style="width:90px;">Level</th>
                                            <th style="width:60px;">Count</th>
                                            <th>Message</th>
                                            <th style="width:320px;">Seen</th>
                                        </tr>
                                        </thead>
                                        <tbody>
                                        @foreach($summary['activity'] as $item)
                                            <tr>
                                                <td><span class="badge bg-{{ $levelColors[$item['level']] ?? 'secondary' }}">{{ $item['level'] }}</span></td>
                                                <td>&times;{{ $item['count'] }}</td>
                                                <td>
                                                    <code style="word-break:break-all;">{{ \Illuminate\Support\Str::limit($item['message'], 160) }}</code>
                                                    @if(!empty($item['location']))
                                                        <div class="small text-muted">{{ $item['location'] }}</div>
                                                    @endif
                                                </td>
                                                <td class="small text-muted">first {{ $item['first'] }} &middot; last {{ $item['last'] }}</td>
                                            </tr>
                                        @endforeach
                                        </tbody>
                                    </table>
                                </div>
                            </div>
                        </div>
                    @endif
                @endif
            </div>
        </div>
    </div>
</div>
