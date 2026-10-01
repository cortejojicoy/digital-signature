<?php

namespace Kukux\DigitalSignature\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Kukux\DigitalSignature\Agent\AgentApiException;
use Kukux\DigitalSignature\Agent\AgentServer;
use Symfony\Component\HttpFoundation\Response;

/**
 * Gate for every agent-facing route: the feature is on, and the agent is at
 * least signature.devices.agent.min_version (426 otherwise, per protocol).
 */
class EnsureAgentVersion
{
    public function handle(Request $request, Closure $next): Response
    {
        if (! AgentServer::enabled()) {
            throw new AgentApiException(404, 'agent_disabled', 'Desktop agent pairing is not enabled on this server.');
        }

        $minimum = (string) config('signature.devices.agent.min_version', '0.0.0');
        $version = (string) $request->header('X-Agent-Version', '0.0.0');

        if (! AgentServer::versionAtLeast($version, $minimum)) {
            throw new AgentApiException(
                426,
                'agent_outdated',
                "Kukux Sign Agent {$minimum} or newer is required. Please update the agent.",
                ['min_version' => $minimum],
            );
        }

        return $next($request);
    }
}
