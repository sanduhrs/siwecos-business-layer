<?php

namespace App;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\QueryException;
use Keygen\Keygen;

/**
 * App\Token.
 *
 * @property int $id
 * @property string $token
 * @property int $credits
 * @property \Carbon\Carbon|null $created_at
 * @property \Carbon\Carbon|null $updated_at
 *
 * @method static \Illuminate\Database\Eloquent\Builder|\App\Token whereCreatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder|\App\Token whereCredits($value)
 * @method static \Illuminate\Database\Eloquent\Builder|\App\Token whereId($value)
 * @method static \Illuminate\Database\Eloquent\Builder|\App\Token whereToken($value)
 * @method static \Illuminate\Database\Eloquent\Builder|\App\Token whereUpdatedAt($value)
 * @mixin \Eloquent
 *
 * @property int $acl_level
 *
 * @method static \Illuminate\Database\Eloquent\Builder|\App\Token whereAclLevel($value)
 */
class Token extends Model
{
    protected $fillable = ['credits', 'token'];

    protected $table = 'tokens';

    public function __construct(array $attributes = [])
    {
        parent::__construct($attributes);
        // Generate token by package gladcodes/keygen
        $this->token = Keygen::token(24)->generate();
    }

    /**
     * @param int $credits
     *
     * @return bool
     */
    public function setTokenCredits(int $credits)
    {
        $this->credits = $credits;

        try {
            $this->save();

            return true;
        } catch (QueryException $queryException) {
            //TODO Log error to Papertrail with Token
            return false;
        }
    }

    public function setAclLevel(int $aclLevel)
    {
        $this->acl_level = $aclLevel;
    }

    public function reduceCredits($amount = 1)
    {
        $this->credits -= $amount;

        try {
            $this->save();

            return true;
        } catch (\Illuminate\Database\QueryException $queryException) {
            // TODO: Log error to Papertrail with Token
            return false;
        }
    }

    public static function reduceToken(string $token, $amount = 1)
    {
        $token = self::getTokenByString($token);
        if ($token instanceof self) {
            $token->credits -= $amount;

            try {
                $token->save();

                return true;
            } catch (\Illuminate\Database\QueryException $queryException) {
                //TODO Log error to Papertrail with Token
                return false;
            }
        }
    }

    public static function getTokenByString(string $token)
    {
        return self::where('token', $token)->first();
    }
}
