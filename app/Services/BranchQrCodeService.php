<?php

namespace App\Services;

use App\Models\Branch;
use App\Models\User;
use BaconQrCode\Renderer\Image\SvgImageBackEnd;
use BaconQrCode\Renderer\ImageRenderer;
use BaconQrCode\Renderer\RendererStyle\RendererStyle;
use BaconQrCode\Writer;

class BranchQrCodeService
{
    public function generate(Branch $branch, ?User $user): string
    {
        abort_unless($user?->is_active && ($user->role === 'admin' || ($user->role === 'manager' && $user->branch_id === $branch->id)), 403);
        $renderer = new ImageRenderer(new RendererStyle(400), new SvgImageBackEnd);

        return (new Writer($renderer))->writeString(route('branch.show', $branch));
    }
}
