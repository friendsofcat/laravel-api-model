<?php

namespace MattaDavi\LaravelApiModel;

use Illuminate\Database\Eloquent\Model;

class ApiModel extends Model
{
    public function getConnection()
    {
        if (method_exists($this, 'getDeletedAtColumn')) {
            $this->configureSoftDeletes();
        }

        return parent::getConnection();
    }

    protected function configureSoftDeletes()
    {
        $configKey = sprintf('database.connections.%s.soft_deletes_column', $this->connection);

        config([$configKey => $this->getDeletedAtColumn()]);
    }
}
