<?php

namespace ProcessMaker\Jobs;

use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Support\Facades\Log;
use ProcessMaker\Models\Process as Definitions;
use ProcessMaker\Models\Script;
use ProcessMaker\Nayra\Contracts\Bpmn\ScriptTaskInterface;
use ProcessMaker\Nayra\Contracts\Bpmn\TokenInterface;
use ProcessMaker\Nayra\Contracts\Engine\ExecutionInstanceInterface;
use Throwable;
use ProcessMaker\Models\User;

class RunScriptTask extends BpmnAction implements ShouldQueue
{

    public $definitionsId;
    public $instanceId;
    public $tokenId;
    public $data;

    /**
     * Create a new job instance.
     * 
     * @param \ProcessMaker\Models\Process $definitions
     * @param \ProcessMaker\Nayra\Contracts\Engine\ExecutionInstanceInterface $instance
     * @param \ProcessMaker\Nayra\Contracts\Bpmn\TokenInterface $token
     * @param array $data
     */
    public function __construct(Definitions $definitions, ExecutionInstanceInterface $instance, TokenInterface $token, array $data)
    {
        $this->definitionsId = $definitions->getKey();
        $this->instanceId = $instance->getKey();
        $this->tokenId = $token->getKey();
        $this->data = $data;
    }

    /**
     * Execute the script task.
     *
     * @return void
     */
    public function action(TokenInterface $token, ScriptTaskInterface $element)
    {
        $scriptRef = $element->getProperty('scriptRef');
        Log::info('Script started: ' . $scriptRef);
        $configuration = json_decode($element->getProperty('config'), true);

        // Check to see if we've failed parsing.  If so, let's convert to empty array.
        if ($configuration === null) {
            $configuration = [];
        }
        $dataStore = $token->getInstance()->getDataStore();
        $data = $dataStore->getData();
        if (empty($scriptRef)) {
            $script = new Script([
                'code' => $element->getScript(),
                'language' => Script::scriptFormat2Language($element->getProperty('scriptFormat', 'application/x-php')),
                'run_as_user_id' => Script::defaultRunAsUser()->id,
            ]);
        } else {
            $script = Script::find($scriptRef);
        }

        try {
            $response = $script->runScript($data, $configuration);
            // Update data
            foreach ($response['output'] as $key => $value) {
                $dataStore->putData($key, $value);
            }
            $element->complete($token);
            Log::info('Script completed: ' . $scriptRef);
        } catch (Throwable $exception) {
            // Change to error status
            $token->setStatus(ScriptTaskInterface::TOKEN_STATE_FAILING);
            $token->getInstance()->logError($exception, $element);
            Log::info('Script failed: ' . $scriptRef . ' - ' . $exception->getMessage());
        }
    }
}
