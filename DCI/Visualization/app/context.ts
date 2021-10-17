export enum Access {
    Public = 358,
    Protected = 357,
    Private = 356
}

export enum RefType {
    Method = 0,
    Property = 1,
    RoleMethod = 2,
    Role = 3,
    RoleAssignment = 4
}

export interface Ref {
    to: string
    type: RefType
    excepted: boolean
    contractCall?: string
}

export interface Method {
    fullName: string
    access: Access
    refs: Ref[]
    role?: string
    tags: string[]
}

export interface Role {
    name: string
    access: Access
    methods: { [name: string]: /* fullName */ string }
    tags: string[]
}

export interface Context {
    name: string
    roles: { [name: string]: Role }
    methods: { [name: string]: Method }
}
