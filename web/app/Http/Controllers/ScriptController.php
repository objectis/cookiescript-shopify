<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Shopify\Exception\CookieNotFoundException;
use Shopify\Exception\MissingArgumentException;
use Shopify\Rest\Admin2023_01\ScriptTag;
use Shopify\Utils;
use stdClass;

class ScriptController extends Controller
{

    public function addScript(Request $request)
    {
        $url = $request->get('url');

        if (is_null($url)) {
            return response('Invalid URL', 400);
        }

        $script_tag = new ScriptTag($this->loadCurrentSession($request),);
        $script_tag->event = "onload";
        $script_tag->src = $url;
        $script_tag->save(
            true
        );

        return response("OK");
    }

    public function getScripts(Request $request)
    {
        $pattern = '/\.cookie-script\.com\/s\/[a-zA-Z0-9]{32}/';
        $matches = [];

        $scripts = ScriptTag::all(
            $this->loadCurrentSession($request),
            [],
            []
        );

        foreach ($scripts as $script) {
            if (preg_match($pattern, $script->src)) {
                $newScriptsObject = new stdClass();
                $newScriptsObject->src = $script->src;
                $newScriptsObject->id = $script->id;

                array_push($matches, $newScriptsObject);
            }
        }

        return $matches;
    }

    public function removeScript(Request $request, $id)
    {
        return ScriptTag::delete(
            $this->loadCurrentSession($request),
            $id,
            [],
            []
        );
    }

    /**
     * @throws CookieNotFoundException
     * @throws MissingArgumentException
     */
    private function loadCurrentSession(Request $request): ?\Shopify\Auth\Session
    {
        return Utils::loadCurrentSession(
            $request->header(),
            $request->cookie(),
            false
        );
    }

}
