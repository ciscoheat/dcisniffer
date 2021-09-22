<?php

namespace PHP_CodeSniffer\Standards\DCI;

final class Context {
    private int $_start;
    public function start() : int { return $this->_start; }

    private int $_end;
    public function end() : int { return $this->_end; }

    private array $_roles = [];
    public function roles() : array { return $this->_roles; }

    private array $_methods = [];
    public function methods() : array { return $this->_methods; }

    public function __construct(int $start, int $end) {
        assert($start > 0, 'Invalid start pos');
        assert($end > $start, 'Invalid end pos');

        $this->_start = $start;
        $this->_end = $end;
    }

    ///////////////////////////////////////////////////////

    public function addRole(Role $role) {
        $this->_roles[$role->name()] = $role;
    }

    public function addMethod(Method $method) {
        $this->_methods[$method->fullName()] = $method;
    }
}

final class Role {
    private string $_name;
    public function name() { return $this->_name; }

    private int $_pos;
    public function pos() { return $this->_pos; }

    private int $_access;
    public function access() { return $this->_access; }
    
    private array $_methods = [];
    public function methods() { return $this->_methods; }

    public function __construct(string $name, int $pos, int $access) {
        $this->_name = $name;
        $this->_pos = $pos;
        $this->_access = $access;
    }

    public function addMethod(string $name, Method $method) {
        $this->_methods[$name] = $method;
    }
}

final class Method {
    private string $_fullName;
    public function fullName() { return $this->_fullName; }

    private int $_start; 
    public function start() { return $this->_start; }

    private int $_end;
    public function end() { return $this->_end; }
    
    private int $_access;
    public function access() { return $this->_access; }
    
    private array $_refs = [];
    public function refs() { return $this->_refs; }

    private ?Role $_role = null;
    public function role() { return $this->_role; }

    public function __construct(string $fullName, int $start, int $end, int $access, ?Role $role) {
        assert(strlen($fullName) > 0, "Empty Method name");
        assert($start > 0, 'Invalid start pos');
        assert($end > $start, 'Invalid end pos');
        assert($access > 0, "Invalid access: $access");

        $this->_fullName = $fullName;
        $this->_start = $start;
        $this->_end = $end;
        $this->_access = $access;
        $this->_role = $role;
    }

    public function addRef(Ref $ref) {
        $this->_refs[$ref->to()] = $ref;
    }

    public function ref($refName) : Ref {
        return $this->_refs[$refName] ?? null;
    }
}

final class Ref {
    const ROLEMETHOD = 0;
    const ROLE = 1;

    private string $_to; 
    public function to() { return $this->_to; }

    private int $_pos;
    public function pos() { return $this->_pos; }

    private int $_type;
    public function type() { return $this->_type; }

    private bool $_isAssignment;
    public function isAssignment() { return $this->_isAssignment; }

    public function __construct(string $to, int $pos, int $type, bool $isAssignment) {
        assert(strlen($to) > 0, "Empty property Ref");
        assert($pos > 0, "Invalid pos: $pos");
        assert($type == self::ROLE || $type == self::ROLEMETHOD, "Invalid type: $type");
        assert(!$isAssignment || ($isAssignment && $type == self::ROLE), "Cannot assign to a RoleMethod ($to)");

        $this->_to = $to;
        $this->_pos = $pos;
        $this->_type = $type;
        $this->_isAssignment = $isAssignment;
    }
}
